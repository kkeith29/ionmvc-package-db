<?php

namespace ionmvc\packages\db\classes\table;

use ionmvc\classes\model;
use ionmvc\packages\db\classes\table;

class row implements \ArrayAccess {

	protected $model;
	protected $data          = [];
	protected $mutators      = [];
	protected $mutator_cache = [];

	public function __construct( $model,$data ) {
		$this->model    = $model;
		$this->data     = $data;
		$this->mutators = model::instance( $model )->get_mutators();
	}

	protected function _isset( $key ) {
		if ( isset( $this->data[$key] ) ) {
			return true;
		}
		if ( isset( $this->mutators[$key] ) ) {
			return true;
		}
		return false;
	}

	protected function _get( $key ) {
		if ( !$this->_isset( $key ) ) {
			return null;
		}
		if ( !isset( $this->mutators[$key] ) ) {
			return $this->data[$key];
		}
		if ( !isset( $this->mutator_cache[$key] ) ) {
			$this->mutator_cache[$key] = call_user_func( $this->mutators[$key],$this->data );
		}
		return $this->mutator_cache[$key];
	}

	public function data( $key ) {
		if ( isset( $this->data[$key] ) ) {
			return $this->data[$key];
		}
		return null;
	}

	public function __isset( $key ) {
		return $this->_isset( $key );
	}

	public function __get( $key ) {
		return $this->_get( $key );
	}

	public function __set( $key,$value ) {
		$this->data[$key] = $value;
	}

	public function __unset( $key ) {
		if ( isset( $this->data[$key] ) ) {
			unset( $this->data[$key] );
		}
	}

	public function offsetSet( $offset,$value ) {
		if ( is_null( $offset ) ) {
			$this->data[] = $value;
		}
		else {
			$this->data[$offset] = $value;
		}
	}
	
	public function offsetExists( $offset ) {
		return $this->_isset( $offset );
	}
	
	public function offsetUnset( $offset ) {
		unset( $this->data[$offset] );
	}
	
	public function offsetGet( $offset ) {
		return $this->_get( $offset );
	}

	public function trashed() {
		if ( (int) $this->data['deleted_at'] === 0 ) {
			return false;
		}
		return true;
	}

	public function delete() {
		return model::new_instance( $this->model )->where('id','=',$this->data['id'])->delete();
	}

	public function destory() {
		return model::new_instance( $this->model )->destroy( $this->data['id'] );
	}

	public function restore() {
		return model::new_instance( $this->model )->where('id','=',$this->data['id'])->restore();
	}

	//handle mutators when outputting

}

?>