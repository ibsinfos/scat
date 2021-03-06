<?php
namespace Scat\Controller;

use \Psr\Container\ContainerInterface;
use \Slim\Http\ServerRequest as Request;
use \Slim\Http\Response as Response;
use \Slim\Views\Twig as View;
use \Respect\Validation\Validator as v;

class Ordure {
  protected $txn, $data, $email;

  public function __construct(
    \Scat\Service\Txn $txn,
    \Scat\Service\Data $data,
    \Scat\Service\Email $email
  ) {
    $this->txn= $txn;
    $this->data= $data;
    $this->email= $email;
  }

  public function pushPrices(Response $response,
                              \Scat\Service\Catalog $catalog) {
    error_log("pushing prices\n");
    $url= ORDURE . '/update-pricing';
    $key= ORDURE_KEY;

    $items= $catalog
              ->getItems()
              ->select_many('retail_price','discount_type','discount')
              ->select_expr('(SELECT SUM(allocated)
                                FROM txn_line
                               WHERE item_id = item.id)',
                            'stock')
              ->select_many('code', 'minimum_quantity', 'purchase_quantity')
              ->select_expr('(SELECT MIN(purchase_quantity)
                                FROM vendor_item
                               WHERE item_id = item.id
                                 AND vendor_id = 7
                                 AND vendor_item.active
                                 AND NOT special_order)',
                            'is_dropshippable')
              ->find_many();

    /* Just in-memory, could be clever and build a stream interface. */
    $data= "retail_price\tdiscount_type\tdiscount\tstock\tcode\t".
           "minimum_quantity\tpurchase_quantity\tis_dropshippable\r\n";

    foreach ($items as $item) {
      $data.= $item->retail_price . "\t" .
              ($item->discount_type ?: 'NULL') . "\t" .
              ($item->discount ?: 'NULL') . "\t" .
              ($item->stock ?: 'NULL') . "\t" .
              $item->code . "\t" .
              $item->minimum_quantity . "\t" .
              $item->purchase_quantity . "\t" .
              ($item->is_dropshippable ?: '0') . "\r\n";
    }

    error_log("generated data, uploading\n");

    $client= new \GuzzleHttp\Client();

    $res= $client->request('POST', $url, [
                             //'debug' => true,
                             'multipart' => [
                               [
                                 'name' => 'prices', 
                                 'contents' => $data,
                                 'filename' => 'prices.txt',
                               ],
                               [
                                 'name' => 'key',
                                 'contents' => $key
                               ]
                             ],
                           ]);

    $body= $res->getBody();

    error_log("done uploading, got: {$body}\n");

    return $response;
  }

  public function pullSignups(Request $request, Response $response) {
    $messages= [];

    $client= new \GuzzleHttp\Client();

    $url= ORDURE . '/get-pending-rewards';
    $res= $client->request('GET', $url,
                           [
                             'debug' => $DEBUG,
                             'query' => [ 'key' => ORDURE_KEY ]
                           ]);

    $updates= json_decode($res->getBody());

    foreach ($updates as $update) {
      $person= null;

      /* First we look by number, and then by email. */
      if (!empty($update->loyalty_number)) {
        $person= $this->data->factory('Person')->where('loyalty_number',
                                                  $update->loyalty_number)
                                          ->find_one();
      }

      if (!$person && !empty($update->email)) {
        $person= $this->data->factory('Person')->where('email',
                                                  $update->email)
                                          ->find_one();
      }

      /* Didn't find them? Create them. */
      if (!$person) {
        $person= $this->data->factory('Person')->create();
        $person->name= $update->name;
        $person->email= $update->email;
        $person->setProperty('phone', $update->phone);
        $person->save();
      }
      /* Otherwise update name, email */
      else {
        if ($update->name) $person->name= $update->name;
        if ($update->email) $person->email= $update->email;
        if ($update->phone)
          $person->setProperty('phone', $update->phone);
        $person->save();
      }

      /* This may trigger an SMS message if it's a new signup. */
      if ($update->rewardsplus) {
        try {
          $person->setProperty('rewardsplus', $update->rewardsplus);
        } catch (\Exception $e) {
          $messages[]= "Exception: " . $e->getMessage();
        }
      }

      /* Handle code */
      try {
        if ($update->code) {
          $code= preg_replace('/[^0-9A-F]/i', '', $update->code);
          $created= substr($code, 0, 8);
          $id= substr($code, -8);
          $created= date("Y-m-d H:i:s", hexdec($created));
          $id= hexdec($id);

          $txn= $this->txn->fetchById($id);
          if (!$txn) {
            throw new \Exception("No such transaction found for '{$update->code}'");
          }

          if ($txn->person_id && $txn->person_id != $person->id) {
            throw new \Exception("Transaction {$id} already assigned to someone else.");
          }

          if ($txn->created != $created) {
            throw new \Exception("Timestamps for transaction {$id} don't match. '{$created}' != '{$txn->created}'");
          }

          $txn->person_id= $person->id;
          $txn->save();
        }
      } catch (\Exception $e) {
        $messages[]= "Exception: " . $e->getMessage();
      }

      $url= ORDURE . '/mark-rewards-processed';
      $res= $client->request('GET', $url,
                             [
                               'debug' => $DEBUG,
                               'query' => [ 'key' => ORDURE_KEY,
                                            'id' => $update->id ]
                             ]);

    }

    $response->getBody()->write(join("\n", $messages));

    return $response;
  }

  public function pullOrders(Request $request, Response $response,
                              \Scat\Service\Shipping $shipping,
                              View $view)
  {
    $client= new \GuzzleHttp\Client();
    $messages= [];

    $url= ORDURE . '/sale/list';
    $res= $client->request('GET', $url,
                           [
                             'debug' => $DEBUG,
                             'query' => [ 'key' => ORDURE_KEY,
                                          'json' => 1 ]
                           ]);

    $sales= json_decode($res->getBody());

    foreach ($sales as $summary) {
      if ($summary->status != 'paid') {
        continue;
      }

      try {

        $url= ORDURE . '/sale/' . $summary->uuid . '/json';
        $res= $client->request('GET', $url,
                               [
                                 'debug' => $DEBUG,
                                 'query' => [ 'key' => ORDURE_KEY ]
                               ]);

        $data= json_decode($res->getBody());

        $this->data->beginTransaction();

        if ($data->sale->person_id) {
          $person=
            $this->data->factory('Person')->find_one($data->sale->person_id);
        } elseif ($data->sale->name) {
          $person=
            $this->data->factory('Person')->where('email', $data->sale->email)
                                          ->where('active', 1)
                                          ->find_one();
        }

        /* Didn't find them? Create them. */
        if (!$person) {
          $person= $this->data->factory('Person')->create();
          $person->name= $data->sale->name;
          $person->email= $data->sale->email;
          $person->save();
        }
        /* Otherwise update name, email */
        else {
          if ($data->sale->name) $person->name= $data->sale->name;
          if ($data->sale->email) $person->email= $data->sale->email;
          $person->save();
        }

        /* Create the base transaction */
        $txn= $this->txn->create('customer');
        $txn->uuid= $data->sale->uuid;
        $txn->online_sale_id= $data->sale->id;
        $txn->status= 'paid';
        $txn->created= $data->sale->created;
        $txn->filled= $data->sale->modified;
        $txn->paid= $data->sale->modified;
        $txn->person_id= $person->id;
        $txn->tax_rate= 0.0;

        $txn->save();

        /* Add items */
        foreach ($data->items as $item) {
          $txn_line= $txn->items()->create();
          $txn_line->txn_id= $txn->id;
          $txn_line->item_id= $item->item_id;
          $txn_line->ordered= $txn_line->allocated= -1 * ($item->quantity);
          $txn_line->override_name= $item->override_name;
          $txn_line->retail_price= $item->retail_price;
          $txn_line->discount_type= $item->discount_type;
          $txn_line->discount= $item->discount;
          $txn_line->discount_manual= $item->discount_manual;
          $txn_line->tic= $item->tic;
          $txn_line->tax= $item->tax;
          $txn_line->save();
        }

        /* Add shipping item */
        if ($data->sale->shipping != 0) {
          switch ($data->sale->shipping_method) {
          case 'bike':
            $code= 'ZZ-DELIVERY';
            break;
          case 'cargo':
            $code= 'ZZ-DELIVERY-CARGO';
            break;
          case 'truck':
          case 'default':
          default:
            $code= 'ZZ-SHIPPING-CUSTOM';
          }

          $item= $this->data->factory('Item')
                   ->where('code', $code)
                   ->find_one();

          $txn_line= $txn->items()->create();
          $txn_line->txn_id= $txn->id;
          $txn_line->item_id= $item->id;
          $txn_line->ordered= $txn_line->allocated= -1;
          $txn_line->tic= $item->tic;
          $txn_line->retail_price= $data->sale->shipping;
          $txn_line->tax= $data->sale->shipping_tax;
          $txn_line->save();
        }

        /* Add payments */
        foreach ($data->payments as $pay) {
          $payment= $txn->payments()->create();
          $payment->txn_id= $txn->id;
          $payment->method= ($pay->method == 'credit') ? 'stripe' : $pay->method;
          if ($pay->method == 'credit') {
            $payment->cc_type= $pay->data->cc_brand;
            $payment->cc_lastfour= $pay->data->cc_last4;
          }

          if ($pay->method == 'other') {
            $payment->method= 'discount';
          }

          $payment->amount= $pay->amount;
          $payment->processed= $pay->processed;
          $payment->data= json_encode($pay->data);
          $payment->save();
        }

        /* Add shipping address */
        if ($data->sale->shipping_address_id != 1) {
          if ($data->shipping_address->easypost_id) {
            $easypost_address= $shipping->retrieveAddress(
              $data->shipping_address->easypost_id
            );
          } else {
            $easypost_address= $shipping->createAddress([
              'verify'  => [ 'delivery' ],
              'name' => $data->shipping_address->name,
              'email' => $data->shipping_address->email,
              'company' => $data->shipping_address->company,
              'street1' => $data->shipping_address->address1,
              'street2' => $data->shipping_address->address2,
              'city' => $data->shipping_address->city,
              'state' => $data->shipping_address->state,
              'zip' => $data->shipping_address->zip5,
              'country' => 'US',
              'phone' => $data->shipping_address->phone,
            ]);
          }

          $address= $this->data->factory('Address')->create();
          $address->easypost_id= $easypost_address->id;
          $address->name= $easypost_address->name;
          $address->email= $easypost_address->email;
          $address->company= $easypost_address->company;
          $address->street1= $easypost_address->street1;
          $address->street2= $easypost_address->street2;
          $address->city= $easypost_address->city;
          $address->state= $easypost_address->state;
          $address->zip= $easypost_address->zip;
          $address->country= $easypost_address->country;
          $address->phone= $easypost_address->phone;
          $address->timezone=
            $easypost_address->verifications->delivery->details->time_zone;
          $address->save();

          $txn->shipping_address_id= $address->id;
        } else {
          $txn->shipping_address_id= 1;
        }
        $txn->save();

        /* Add notes */
        foreach ($data->notes as $sale_note) {
          $note= $txn->notes()->create();
          $note->kind= 'txn';
          $note->attach_id= $txn->id;
          $note->person_id= $sale_note->person_id;
          $note->content= $sale_note->content;
          $note->added= $sale_note->added;
          $note->todo= 1;
          $note->save();
        }

        // Reward loyalty
        $txn->rewardLoyalty();

        // Mark status on Ordure

        $url= ORDURE . '/sale/' . $summary->uuid . '/set-status';
        $res= $client->request('POST', $url,
                               [
                                 'debug' => $DEBUG,
                                 'headers' => [
                                   'X-Requested-With' => 'XMLHttpRequest',
                                 ],
                                 'form_params' => [
                                   'key' => ORDURE_KEY,
                                   'status' => 'processing'
                                 ]
                               ]);

        $this->data->commit();

        // Send order confirmation
        $invoice= $txn->formatted_number();
        $subject= "Thanks for shopping with us! (Invoice #{$invoice})";

        $method= $txn->shipping_address_id == 1
                  ? "is ready for pick up" : "has been shipped";
        $content= <<<EMAIL
Thank you for shopping at Raw Materials Art Supplies!

Your order is now being processed, and you will receive another email when your order $method or if we have other updates.

Let us know if there is anything else that we can do to help.
EMAIL;

        $body= $view->fetch('email/invoice.html', [
          'txn' => $txn,
          'subject' => $subject,
          'content' => $content,
        ]);

        $res= $this->email->send([ $person->email => $person->name],
                                  $subject, $body, $attachments);
      }
      catch (Exception $e) {
        $this->data->rollBack();
        $messages[]= "Exception: " . $e->getMessage();
      }
    }

    return $response;
  }

  public function processAbandonedCarts(Request $request, Response $response) {
    $loader= new \Twig\Loader\FilesystemLoader('../ui');
    $twig= new \Twig\Environment($loader, [
      'cache' => false
    ]);

    $client= new \GuzzleHttp\Client();

    $url= ORDURE . '/sale/list';
    $res= $client->request('GET', $url,
                           [
                             'debug' => $DEBUG,
                             'query' => [ 'key' => ORDURE_KEY,
                                          'carts' => 1,
                                          'yesterday' => 1,
                                          'json' => 1 ]
                           ]);

    $sales= json_decode($res->getBody());

    foreach ($sales as $summary) {
      if ($summary->status != 'cart') {
        continue;
      }

      $url= ORDURE . '/sale/' . $summary->uuid . '/json';
      $res= $client->request('GET', $url,
                             [
                               'debug' => $DEBUG,
                               'query' => [ 'key' => ORDURE_KEY ]
                             ]);

      $data= json_decode($res->getBody(), true);

      if (!count($data['items']) || !$data['sale']['email']) {
        continue;
      }

      $data['call_to_action_url']= ORDURE . '/cart?uuid=' . $data['sale']['uuid'];

      $template= $twig->load('email/abandoned-cart.html');

      $this->email->send([ $data['sale']['email'] => $data['sale']['name'] ],
                          $template->renderBlock('title', $data),
                          $template->render($data));
    }

    return $response;
  }

  public function fixLoyalty(Request $request, Response $response) {
    $txns= $this->txn->find('customer', 0, 100000)
                ->where_not_null('online_sale_id')
                ->find_many();

    foreach ($txns as $txn) {
      if (!count($txn->loyalty()->find_many())) {
        $txn->rewardLoyalty();
      }
    }

    return $response->withJson([]);
  }

}
