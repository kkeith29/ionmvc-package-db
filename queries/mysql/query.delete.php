<?php

namespace ionmvc\packages\db\queries\mysql;

use ionmvc\packages\db\classes\mysql\query;

class delete extends query {

	protected $clauses = array('where','order_by','limit');

	public function build() {
		$query = 'DELETE FROM';
		if ( !$this->data->is_set('where') ) {
			$query = 'TRUNCATE TABLE';
		}
		$query .= ' `' . $this->table->name() . '`';
		foreach( $this->clauses as $part ) {
			if ( $this->data->is_set( $part ) ) {
				$data = $this->data->get( $part );
				switch( $part ) {
					case 'where':
						$query .= " WHERE {$data}";
					break;
					case 'order_by':
						$query .= ' ORDER BY ' . implode( ',',$data );
					break;
					case 'limit':
						$query .= " LIMIT {$data['start']}" . ( isset( $data['total'] ) ? ", {$data['total']}" : '' );
					break;
				}
			}
		}
		return $query;
	}

}

?>