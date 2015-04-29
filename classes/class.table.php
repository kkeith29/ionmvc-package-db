<?php

namespace ionmvc\packages\db\classes;

use ionmvc\classes\autoloader;
use ionmvc\classes\config\string as config_string;
use ionmvc\classes\model;
use ionmvc\classes\time;
use ionmvc\exceptions\app as app_exception;

class table {

	const rel_one_to_one   = 1;
	const rel_one_to_many  = 2;
	const rel_many_to_one  = 3;
	const rel_many_to_many = 4;

	public $table_name = null;
	public $db;
	public $table;
	public $name;

	protected $config = array(
		'with_trashed' => false,
		'trashed_only' => false
	);
	protected $relationships = array();
	protected $relationship = array();
	protected $data = array();
	protected $events = array(
		'pre_create' => array(),
		'pre_update' => array()
	);
	protected $withs = array();
	protected $relationship_withs = array();
	protected $calls = array();

	protected $mutators = array();

	protected $soft_delete = true;
	protected $timestamps = true;

	public function __construct() {
		if ( is_null( $this->table_name ) ) {
			throw new app_exception('Table name not set');
		}
		$this->db    = ( isset( $this->connection ) ? db::connection( $this->connection ) : db::current() );
		$this->table = $this->db->table( $this->table_name );
		$this->name  = autoloader::class_id( get_class( $this ) );
		$this->alias = str_replace( '/','_',$this->name );
	}

	public function __call( $method,$args ) {
		$_method = "_{$method}";
		switch( $method ) {
			case 'contact':
			case 'concat_ws':
			case 'where':
			case 'where_in':
			case 'order_by':
			case 'limit':
				$this->add_call( $method,$args );
				return $this;
			break;
		}
		if ( method_exists( $this,$_method ) ) {
			return call_user_func_array( array( $this,$_method ),$args );
		}
		throw new app_exception( 'Unable to find method %s',$method );
	}

	public static function __callStatic( $method,$args ) {
		$instance = new static;
		return call_user_func_array( array( $instance,$method ),$args );
	}

	public function __isset( $key ) {
		return isset( $this->data[$key] );
	}

	public function __get( $key ) {
		if ( isset( $this->data[$key] ) ) {
			return $this->data[$key];
		}
		return $this->data[$key];
	}

	public function __set( $key,$value ) {
		$this->data[$key] = $value;
	}

	public function __unset( $key ) {
		if ( isset( $this->data[$key] ) ) {
			unset( $this->data[$key] );
		}
	}

	public function _db() {
		return $this->db;
	}

	public function _table() {
		return $this->table;
	}

	protected function add_relationship( $type,$name,$config ) {
		if ( !isset( $this->relationships[$type] ) ) {
			$this->relationships[$type] = array();
		}
		$config['_name'] = $name; 
		if ( !isset( $config['model'] ) ) {
			$config['model'] = $name;
		}
		$this->relationships[$type][$name] = $config;
		$this->relationship[$name] = $type;
	}

	public function one_to_one( $name,$config=array() ) {
		$this->add_relationship( self::rel_one_to_one,$name,$config );
	}

	public function one_to_many( $name,$config=array() ) {
		if ( !isset( $config['name'] ) ) {
			throw new app_exception('Name config item is required');
		}
		$this->add_relationship( self::rel_one_to_many,$name,$config );
	}

	public function many_to_one( $name,$config=array() ) {
		$this->add_relationship( self::rel_many_to_one,$name,$config );
	}

	public function many_to_many( $name,$config=array() ) {
		if ( !isset( $config['name'] ) ) {
			throw new app_exception('Name config item is required');
		}
		if ( !isset( $config['pivot_model'] ) ) {
			throw new app_exception('Pivot model config item is required');
		}
		$this->add_relationship( self::rel_many_to_many,$name,$config );
	}

	public function get_relationship( $name ) {
		if ( !isset( $this->relationship[$name] ) ) {
			return false;
		}
		return array(
			'type'   => $this->relationship[$name],
			'config' => $this->relationships[$this->relationship[$name]][$name]
		);
	}

	public function event( $events,\Closure $function ) {
		if ( !is_array( $events ) ) {
			$events = array( $events );
		}
		foreach( $events as $event ) {
			if ( !isset( $this->events[$event] ) ) {
				throw new app_exception( 'Event %s does not exist',$event );
			}
			$this->events[$event][] = $function;
		}
	}

	protected function has_events( $event ) {
		if ( !isset( $this->events[$event] ) ) {
			return false;
		}
		return ( count( $this->events[$event] ) > 0 );
	}

	public function mutator( $field,\Closure $function ) {
		$this->mutators[$field] = $function;
	}

	public function get_mutators() {
		return $this->mutators;
	}

	protected function add_call( $method,$args ) {
		$this->calls[] = compact('method','args');
	}

	public function handle_calls( $query ) {
		if ( count( $this->calls ) === 0 ) {
			return;
		}
		foreach( $this->calls as $call ) {
			call_user_func_array( array( $query,$call['method'] ),$call['args'] );
		}
	}

	public function _with( $name,$config=array() ) {
		if ( is_string( $config ) ) {
			$config = config_string::parse( $config );
		}
		if ( strpos( $name,'.' ) === false ) {
			$this->withs[] = array(
				'name'   => $name,
				'config' => $config
			);
			return $this;
		}
		$names = explode( '.',$name );
		$main_name = $names[0];
		$this->withs[] = array(
			'name'   => $main_name,
			'config' => array()
		);
		$last_rel = false;
		foreach( $names as $name ) {
			$full_name = ( $last_rel === false ? $name : "{$last_rel['_name']}.{$name}" );
			if ( isset( $this->relationship[$full_name] ) ) {
				$last_rel = $this->relationships[$this->relationship[$full_name]][$full_name];
				continue;
			}
			if ( $last_rel === false ) {
				throw new app_exception( 'Main relationship \'%s\' must be defined when using nested',$main_name );
			}
			if ( ( $rel = model::instance( $last_rel['model'] )->get_relationship( $name ) ) === false ) {
				throw new app_exception( 'Unable to find nested relationship: %s',$full_name );
			}
			$rel['config']['parent'] = $full_name;
			$this->add_relationship( $rel['type'],$full_name,$rel['config'] );
			if ( !isset( $this->relationship_withs[$main_name] ) ) {
				$this->relationship_withs[$main_name] = array();
			}
			$this->relationship_withs[$main_name][] = array(
				'name'   => $name,
				'config' => $config
			);
		}
		return $this;
	}

	public function _trashed_only() {
		if ( !$this->soft_delete ) {
			throw new app_exception('Trashed records only exist when using soft deletes');
		}
		$this->config['trashed_only'] = true;
		return $this;
	}

	public function _with_trashed() {
		if ( !$this->soft_delete ) {
			throw new app_exception('Trashed records only exist when using soft deletes');
		}
		$this->config['with_trashed'] = true;
		return $this;
	}

	public function _lock_write() {
		return $this->db->execute(sprintf( 'LOCK TABLES `%s` AS `%s` WRITE,`%s` WRITE',$this->table_name,$this->alias,$this->table_name ));
	}

	public function _unlock() {
		return $this->db->execute('UNLOCK TABLES');
	}

	protected function handle_to_one( $query ) {
		if ( count( $this->withs ) === 0 ) {
			return;
		}
		foreach( $this->withs as $with ) {
			$type = $this->relationship[$with['name']];
			if ( !in_array( $type,array( self::rel_one_to_one,self::rel_many_to_one ) ) ) {
				continue;
			}
			$relationship = $this->relationships[$type][$with['name']];
			if ( isset( $relationship['parent'] ) && !in_array( $this->relationship[$relationship['parent']],array( self::rel_one_to_one,self::rel_many_to_one ) ) ) {
				continue;
			}
			$m = model::instance( $relationship['model'] );
			$parent = ( isset( $relationship['parent'] ) ? model::instance( $relationship['parent'] ) : $this );
			switch( $type ) {
				case self::rel_one_to_one:
					$key = ( !isset( $relationship['key'] ) ? 'id' : $relationship['key'] );
					$foreign_key = ( !isset( $relationship['foreign_key'] ) ? "{$parent->alias}_id" : $relationship['foreign_key'] );
				break;
				case self::rel_many_to_one:
					$key = ( !isset( $relationship['key'] ) ? "{$m->alias}_id" : $relationship['key'] );
					$foreign_key = ( !isset( $relationship['foreign_key'] ) ? 'id' : $relationship['foreign_key'] );
				break;
			}
			if ( !isset( $relationship['join_type'] ) ) {
				$relationship['join_type'] = 'inner';
			}
			switch( $relationship['join_type'] ) {
				case 'inner':
				case 'outer':
				case 'left':
				case 'right':
					$call = "{$relationship['join_type']}_join";
					break;
				default:
					throw new app_exception( 'Invalid join type: %s',$relationship['join_type'] );
					break;
			}
			$query->{$call}( $m->table_name,$m->alias )->on_columns("{$parent->alias}.{$key}",'=',"{$m->alias}.{$foreign_key}");
			if ( isset( $relationship['query'] ) ) {
				call_user_func( $relationship['query'],$query );
			}
			if ( isset( $with['config']['conditions'] ) ) {
				foreach( $with['config']['conditions'] as $condition => $data ) {
					if ( !isset( $relationship['conditions'][$condition] ) ) {
						throw new app_exception( 'Condition %s not found',$condition );
					}
					call_user_func( $relationship['conditions'][$condition],$query,$data );
				}
			}
			if ( isset( $with['config']['query'] ) ) {
				call_user_func( $with['config']['query'],$query );
			}
			if ( isset( $relationship['fields'] ) ) {
				$query->fields( $relationship['fields'] );
			}
		}
	}

	public function _find( $id ) {
		return $this->where('id','=',$id)->limit(1)->get()->first();
		/*$query = $this->table->query('select')->table_alias( $this->alias )->fields('*')->where( 'id','=',$id );
		$this->handle_to_one( $query );
		$result = $query->limit(1)->execute()->first();
		if ( $result === false ) {
			return false;
		}
		return new table\row( $this->name,$result->to_array() );*/
	}

	public function _all() {
		$query = $this->table->query('select')->table_alias( $this->alias )->fields('*');
		if ( $this->soft_delete ) {
			$query->where('deleted_at','= 0');
		}
		$rowset = new table\rowset;
		$results = $query->execute()->results();
		foreach( $results as $result ) {
			$rowset->add_row( new table\row( $this->name,$result->to_array() ) );
		}
		unset( $results );
		return $rowset;
	}

	public function _get( $config=array() ) {
		$config = array_merge( $this->config,$config );
		$query = $this->table->query('select')->table_alias( $this->alias )->fields('*');
		$this->handle_calls( $query );
		if ( $this->soft_delete ) {
			if ( $config['trashed_only'] ) {
				$query->where('deleted_at','!= 0');
			}
			elseif ( !$config['with_trashed'] ) {
				$query->where('deleted_at','= 0');
			}
		}
		if ( !isset( $config['with_relationships'] ) || $config['with_relationships'] ) {
			$this->handle_to_one( $query );
		}
		if ( isset( $config['return_query'] ) && $config['return_query'] ) {
			return $query;
		}
		$rowset = new table\rowset;
		$results = $query->execute()->results();
		$ids = array();
		foreach( $results as $result ) {
			$ids[] = $result->id;
			$rowset->add_row( new table\row( $this->name,$result->to_array() ) );
		}
		
		if ( ( !isset( $config['with_relationships'] ) || $config['with_relationships'] ) && count( $this->withs ) > 0 ) {
			foreach( $this->withs as $with ) {
				switch( $this->relationship[$with['name']] ) {
					case self::rel_one_to_many:
						$relationship = $this->relationships[$this->relationship[$with['name']]][$with['name']];
						$model = model::new_instance( $relationship['model'] )->where_in( "{$this->alias}_id",$ids );
						if ( isset( $this->relationship_withs[$with['name']] ) ) {
							foreach( $this->relationship_withs[$with['name']] as $with ) {
								$model->with( $with['name'],$with['config'] );
							}
						}
						$data = $model->get();
						$foreign_key = "{$this->alias}_id";
						$_data = array();
						if ( count( $data ) > 0 ) {
							foreach( $data as $datum ) {
								if ( !isset( $_data[$datum[$foreign_key]] ) ) {
									$_data[$datum[$foreign_key]] = new table\rowset;
								}
								$_data[$datum[$foreign_key]]->add_row( $datum );
								unset( $datum );
							}
						}
						unset( $data );
						foreach( $rowset as $row ) {
							if ( !isset( $_data[$row['id']] ) ) {
								$row->{$relationship['name']} = new table\rowset;
								continue;
							}
							$row->{$relationship['name']} = $_data[$row->id];
							unset( $row );
						}
						unset( $_data );
					break;
					case self::rel_many_to_many:
						$relationship = $this->relationships[$this->relationship[$with['name']]][$with['name']];
						$model = model::new_instance( $relationship['model'] );
						if ( isset( $this->relationship_withs[$with['name']] ) ) {
							foreach( $this->relationship_withs[$with['name']] as $with ) {
								$model->with( $with['name'],$with['config'] );
							}
						}
						$pivot_model = model::new_instance( $relationship['pivot_model'] )->where_in( "{$this->alias}_id",$ids );
						$data = $pivot_model->get();
						$pivot_key_1 = "{$this->alias}_id";
						$pivot_key_2 = "{$model->name}_id";
						$_data_1 = array();
						$_ids = array();
						if ( count( $data ) > 0 ) {
							foreach( $data as $datum ) {
								if ( !isset( $_data_1[$datum[$pivot_key_1]] ) ) {
									$_data_1[$datum[$pivot_key_1]] = new table\rowset;
								}
								if ( !in_array( $datum[$pivot_key_2],$_ids ) ) {
									$_ids[] = $datum[$pivot_key_2];
								}
								$_data_1[$datum[$pivot_key_1]]->add_row( $datum );
								unset( $datum );
							}
						}
						unset( $data );
						$_data_2 = array();
						$data = $model->where_in( 'id',$_ids )->get();
						foreach( $data as $datum ) {
							$_data_2[$datum['id']] = $datum;
							unset( $datum );
						}
						unset( $data );
						foreach( $rowset as $row ) {
							if ( !isset( $_data_1[$row['id']] ) ) {
								$row->{$relationship['name']} = new table\rowset;
								continue;
							}
							$_rowset = new table\rowset;
							foreach( $_data_1[$row['id']] as $_row ) {
								if ( !isset( $_data_2[$_row[$pivot_key_2]] ) ) {
									continue;
								}
								$__row = clone $_data_2[$_row[$pivot_key_2]];
								$__row->pivot = $_row;
								$_rowset->add_row( $__row );
								unset( $__row );
							}
							$row->{$relationship['name']} = $_rowset;
							unset( $row,$_rowset );
						}
						unset( $_data_1,$_data_2 );
					break;
				}
			}
		}
		unset( $ids );
		return $rowset;
	}

	public function _get_query() {
		return $this->_get(array(
			'return_query' => true
		));
	}

	public function _create( $data ) {
		if ( $this->timestamps ) {
			$data['created_at'] = time::now();
			$data['updated_at'] = 0;
		}
		if ( $this->soft_delete ) {
			$data['deleted_at'] = 0;
		}
		if ( $this->has_events('pre_create') ) {
			foreach( $this->events['pre_create'] as $event ) {
				$data = call_user_func( $event,$data );
			}
		}
		return $this->table->query('insert')->fields( $data )->execute()->insert_id();
	}

	public function _update( $data ) {
		$query = $this->table->query('update');
		$this->handle_calls( $query );
		if ( $this->timestamps ) {
			$data['updated_at'] = time::now();
		}
		if ( $this->has_events('pre_update') ) {
			foreach( $this->events['pre_update'] as $event ) {
				$data = call_user_func( $event,$data );
			}
		}
		return $query->fields( $data )->execute()->affected_rows();
	}

	public function _delete( $config=array() ) {
		if ( !isset( $config['force'] ) ) {
			$config['force'] = false;
		}
		if ( isset( $this->relationships[self::rel_one_to_one] ) || isset( $this->relationships[self::rel_one_to_many] ) || isset( $this->relationships[self::rel_many_to_many] ) ) {
			$results = $this->_get(array(
				'with_relationships' => false,
				'with_trashed' => $config['force']
			));
			foreach( array( self::rel_one_to_one,self::rel_one_to_many,self::rel_many_to_many ) as $type ) {
				if ( !isset( $this->relationships[$type] ) ) {
					continue;
				}
				foreach( $this->relationships[$type] as $name => $info ) {
					$key = ( isset( $info['key'] ) ? $info['key'] : 'id' );
					$foreign_key = ( isset( $info['foreign_key'] ) ? $info['foreign_key'] : "{$this->alias}_id" );
					$ids = array();
					foreach( $results as $result ) {
						$ids[] = $result[$key];
					}
					if ( count( $ids ) === 0 ) {
						continue;
					}
					model::new_instance(( $type !== self::rel_many_to_many ? $info['model'] : $info['pivot_model'] ))->where_in( $foreign_key,$ids )->delete( $config );
				}
			}
		}
		$query = $this->table->query(( $this->soft_delete && !$config['force'] ? 'update' : 'delete' ));
		$this->handle_calls( $query );
		if ( $this->soft_delete && !$config['force'] ) {
			$query->fields(array(
				'deleted_at' => time::now()
			));
		}
		return $query->execute()->affected_rows();
	}

	public function _force_delete() {
		if ( !$this->soft_delete ) {
			throw new app_exception('Force deletion is only available when using soft deletes');
		}
		return $this->_delete(array(
			'force' => true
		));
	}

	public function _restore() {
		if ( !$this->soft_delete ) {
			throw new app_exception('Restore is only available when using soft deletes');
		}
		if ( isset( $this->relationships[self::rel_one_to_one] ) || isset( $this->relationships[self::rel_one_to_many] ) || isset( $this->relationships[self::rel_many_to_many] ) ) {
			$results = $this->_get(array(
				'with_relationships' => false,
				'with_trashed' => true
			));
			foreach( array( self::rel_one_to_one,self::rel_one_to_many,self::rel_many_to_many ) as $type ) {
				if ( !isset( $this->relationships[$type] ) ) {
					continue;
				}
				foreach( $this->relationships[$type] as $name => $info ) {
					$key = ( isset( $info['key'] ) ? $info['key'] : 'id' );
					$foreign_key = ( isset( $info['foreign_key'] ) ? $info['foreign_key'] : "{$this->alias}_id" );
					$ids = array();
					foreach( $results as $result ) {
						$ids[] = $result[$key];
					}
					if ( count( $ids ) === 0 ) {
						continue;
					}
					model::new_instance(( $type !== self::rel_many_to_many ? $info['model'] : $info['pivot_model'] ))->where_in( $foreign_key,$ids )->restore();
				}
			}
		}
		return $this->_update(array(
			'deleted_at' => 0
		));
	}

	public function _destroy() {
		$args = func_get_args();
		if ( count( $args ) === 0 ) {
			throw new app_exception('Destroy method requires an id or set of ids');
		}
		if ( is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$this->where_in('id',$args);
		return $this->_delete();
	}

}

?>