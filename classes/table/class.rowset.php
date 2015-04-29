<?php

namespace ionmvc\packages\db\classes\table;

class rowset implements \Iterator,\Countable {

	protected $rows = array();
	protected $position = 0;

	public function add_row( row $row ) {
		$this->rows[] = $row;
	}

	public function first() {
		return reset( $this->rows );
	}

	public function rewind() {
		$this->position = 0;
	}

	public function current() {
		return $this->rows[$this->position];
	}
	
	public function key() {
		return $this->position;
	}
	
	public function next() {
		++$this->position;
	}
	
	public function valid() {
		return isset( $this->rows[$this->position] );
	}

	public function count() {
		return count( $this->rows );
	}

	//possibly add functions to run on all rows

}

?>