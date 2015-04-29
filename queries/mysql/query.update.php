<?php

namespace ionmvc\packages\db\queries\mysql;

use ionmvc\packages\db\classes\mysql\query;
use ionmvc\packages\db\classes\mysql\table;

class update extends query {

	protected $clauses = array('set','where','order_by','limit');

	public function fields( $data,$types=array() ) {
		$values = array();
		$i = 0;
		foreach( $data as $key => $val ) {
			$value = "`{$key}` = " . table::get_format( ( isset( $types[$key] ) ? $types[$key] : $this->table->column_type( $key ) ) );
			$values[] = sprintf( $value,$this->table->db()->escape( $val ) );
			$i++;
		}
		if ( $this->data->is_set('set') ) {
			$values = array_merge( $this->data->get('set'),$values );
		}
		$this->data->set('set',$values);
		return $this;
	}

	public function build() {
		$query = 'UPDATE `' . $this->table->name() . '`';
		foreach( $this->clauses as $part ) {
			if ( $this->data->is_set( $part ) ) {
				$data = $this->data->get( $part );
				switch( $part ) {
					case 'set':
						$query .= ' SET ' . implode( ', ',$data );
					break;
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