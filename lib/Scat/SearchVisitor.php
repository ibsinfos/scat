<?php
namespace Scat;

use OE\Lukas\QueryTree\Item;
use OE\Lukas\QueryTree\Word;
use OE\Lukas\QueryTree\Text;
use OE\Lukas\QueryTree\ExplicitTerm;
use OE\Lukas\QueryTree\SubExpression;
use OE\Lukas\QueryTree\Negation;
use OE\Lukas\QueryTree\DisjunctiveExpressionList;
use OE\Lukas\QueryTree\ConjunctiveExpressionList;

class SearchVisitor implements \OE\Lukas\Visitor\IQueryItemVisitor
{
  private $terms= [];
  private $current;

  public function __construct()
  {
  }

  public function where_clause() {
    return $this->current[0] ?: "1=1";
  }

  public function visitWord(Word $word)
  {
    // We just ignore AND, it's noise
    if ($word->getWord() != 'AND') {
      $term= addslashes($word->getWord());
      $this->current[]= "(item.name LIKE '%$term%' OR brand.name LIKE '%$term%' OR item.code LIKE '%$term%' OR barcode.code LIKE '%$term%')";
    }
  }

  public function visitText(Text $text)
  {
    $term= addslashes($text->getText());
    $this->current[]= "(item.name LIKE '%$term%' OR brand.name LIKE '%$term%' OR item.code LIKE '%$term%' OR barcode.code LIKE '%$term%')";
  }

  public function visitExplicitTerm(ExplicitTerm $term)
  {
    $name= $term->getNominator()->getWord();
    $value= $term->getTerm()->getToken();
    /* TODO handle different terms here */
    switch ($name) {
    case 'code':
      $this->current[]= "(item.code LIKE '$value%')";
      break;
    case 'item':
      $this->current[]= "(item.id = '$value')";
      break;
    case 'brand':
      if (is_numeric($value)) {
        $this->current[]= "(item.brand = '$value')";
      } else {
        // XXX search name and slug?
        $this->current[]= "(brand.slug = '$value')";
      }
      break;
    case 'product':
      if (is_numeric($value)) {
        $this->current[]= "(item.product_id = '$value')";
      } else {
        // XXX search name and slug?
        $this->current[]= "(product.slug = '$value')";
      }
      break;
    case 'name':
      $this->current[]= "(item.name LIKE '%$value%')";
      break;
    case 'msrp':
      $this->current[]= "(item.retail_price = '$value')";
      break;
    case 'discount':
      $this->current[]= "(item.discount = '$value')";
      break;
    case 'min':
      $this->current[]= "(item.minimum_quantity = '$value')";
      break;
    case 're':
      $this->current[]= "(item.code RLIKE '$value')";
      break;
    default:
      $this->current[]= "(item.$name = '$value')";
    }
  }

  public function visitSubExpression(SubExpression $sub)
  {
    $old= $this->current;
    $this->current= [];
    $sub->getSubExpression()->accept($this);
    // XXX can $this->current ever have more than one item?
    $old[]= '(' . join(' AND ', $this->current) . ')';
    $this->current= $old;
  }

  public function visitNegation(Negation $negation)
  {
    $old= $this->current;
    $this->current= [];

    $negation->getSubExpression()->accept($this);

    $old[]= 'NOT ' . join(' AND ', $this->current);
    $this->current= $old;
  }

  public function visitDisjunctiveExpressionList(DisjunctiveExpressionList $list)
  {
    $old= $this->current;
    $this->current= [];

    foreach($list->getExpressions() as $expression)
    {
        $expression->accept($this);
    }

    $old[]= join(' OR ', $this->current);
    $this->current= $old;
  }

  public function visitConjunctiveExpressionList(ConjunctiveExpressionList $list)
  {
    $old= $this->current;
    $this->current= [];

    foreach($list->getExpressions() as $expression)
    {
        $expression->accept($this);
    }

    $old[]= join(' AND ', $this->current);
    $this->current= $old;
  }

  public function dump() {
    echo $this->current[0];
  }
}