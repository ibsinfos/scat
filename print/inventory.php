<?
require '../scat.php';
require '../lib/item.php';

$q= $_REQUEST['q'];
if ($q) {
  if (!preg_match('/stocked:/i', $q)) {
    $q= $q . " stocked:1";
  }

  $items= item_find($db, $q, 0);
} elseif ($item_list= $_REQUEST['items']) {
  $items= [];
  $item_ids= explode(',', $item_list);
  foreach ($item_ids as $id) {
    $items[]= item_load($db, $id);
  }
}

if (!$items) die_json("No items found.");

$product_id= $items[0]['product_id'];
$variation= $items[0]['variation'];
$use_short_name= true;
$use_variation= false;
foreach ($items as $item) {
  if ($item['product_id'] != $product_id) {
    $use_short_name= false;
  }
  if ($item['variation'] != $variation) {
    $use_variation= true;
  }
}

$product= ($use_short_name ?
           \Titi\Model::factory('Product')->where('id', $product_id)->find_one() :
           null);

if ($product && !$q) {
  $q= "product:{$product->id} stocked:1";
}

$loader= new \Twig\Loader\FilesystemLoader('../ui/');
$twig= new \Twig\Environment($loader, [ 'cache' => false ]);

$template= $twig->load('print/inventory.html');
$html= $template->render([
  'items' => $items,
  'product' => $product,
  'use_short_name' => $use_short_name,
  'use_variation' => $use_variation,
  'q' => $q,
]);

if (defined('PRINT_DIRECT')) {
  define('_MPDF_TTFONTDATAPATH', '/tmp/ttfontdata');
  @mkdir(_MPDF_TTFONTDATAPATH);

  $mpdf= new \Mpdf\Mpdf([ 'mode' => 'utf-8', 'format' => 'letter',
                          'tempDir' => '/tmp',
                          'default_font_size' => 11  ]);
  $mpdf->setAutoTopMargin= 'stretch';
  $mpdf->setAutoBottomMargin= 'stretch';
  $mpdf->writeHTML($html);

  $tmpfname= tempnam("/tmp", "rec");

  if ($DEBUG) {
    $mpdf->Output();
    exit;
  }

  $mpdf->Output($tmpfname, 'f');

  if (!defined('CUPS_HOST')) {
    $printer= REPORT_PRINTER;
    $option= "";
    shell_exec("lpr -P$printer $option $tmpfname");
  } else {
    $client= new \Smalot\Cups\Transport\Client(CUPS_USER, CUPS_PASS,
                                               [ 'remote_socket' => 'tcp://' .
                                                                    CUPS_HOST
                                                                    ]);
    $builder= new \Smalot\Cups\Builder\Builder(null, true);
    $responseParser= new \Smalot\Cups\Transport\ResponseParser();

    $printerManager= new \Smalot\Cups\Manager\PrinterManager($builder,
                                                             $client,
                                                             $responseParser);
    $printer= $printerManager->findByUri('ipp://' . CUPS_HOST .
                                         '/printers/' . REPORT_PRINTER);

    $jobManager= new \Smalot\Cups\Manager\JobManager($builder,
                                                     $client,
                                                     $responseParser);

    $job= new \Smalot\Cups\Model\Job();
    $job->setName('job create file');
    $job->setCopies(1);
    $job->setPageRanges('1-1000');
    $job->addFile($tmpfname);
    $result= $jobManager->send($printer, $job);
  }

  echo jsonp(array("result" => "Printed."));
} else {
  echo $html;
}

