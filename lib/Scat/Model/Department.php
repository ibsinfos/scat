<?php
namespace Scat\Model;

class Department extends \Scat\Model {
  private $old_slug;

  public function full_slug() {
    return
      ($this->parent_id ? $this->parent()->slug . '/' : '') .
      $this->slug;
  }

  public function parent() {
    return $this->belongs_to('Department', 'parent_id')->find_one();
  }

  public function departments($only_active= true) {
    return $this->has_many('Department', 'parent_id')
                ->where_gte('department.active', (int)$only_active);
  }

  public function products($only_active= true) {
    return $this->has_many('Product')
                ->where_gte('product.active', (int)$only_active);
  }

  // XXX A gross hack to find when slug changes.
  function set_orm($orm) {
    parent::set_orm($orm);
    if ($this->id) {
      $this->old_slug= $this->full_slug();
    }
  }

  function save() {
    if ($this->id &&
        ($this->is_dirty('slug') || $this->is_dirty('parent_id'))) {
      $new_slug= $this->full_slug();
      error_log("Redirecting {$this->old_slug} to $new_slug");
      $redir= self::factory('Redirect')->create();
      $redir->source= $this->old_slug;
      $redir->dest= $new_slug;
      $redir->save();
    }
    parent::save();
  }
}
