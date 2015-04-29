<?php

namespace ionmvc\packages\db\queries\mysql;

use ionmvc\packages\db\classes\mysql\query;
use ionmvc\packages\db\classes\mysql\table;

class insert extends query {

	private function prepare_column( $val ) {
		return "`{$val}`";
	}

	public function fields( $data ) {
		list( $columns,$values ) = array( array_keys( $data ),array_values( $data ) );
		$this->data->set('fields',array_map( array( $this,'prepare_column' ),$columns ));
		foreach( $columns as $i => $column ) {
			$value = table::get_format( $this->table->column_type( $column ) );
			$values[$i] = sprintf( $value,$this->table->db()->escape( $values[$i] ) );
		}
		$this->data->set('values',$values);
		return $this;
	}

	public function build() {
		$query = 'INSERT INTO `' . $this->table->name() . '` (' . implode( ',',$this->data->get('fields') ) . ') VALUES (' . implode( ',',$this->data->get('values') ) . ')';
		return $query;
	}

}

?>