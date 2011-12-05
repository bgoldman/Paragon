<?php
/*
paragon.php
Copyright (c) 2011 Brandon Goldman
Released under the MIT License.
*/

class Paragon {
	/**
	 * Private Variables
	 */
	 
	private $_field_values = array();
	private $_is_created = false;
	private $_relationship_instances = array();
	
	
	/**
	 * Static Variables
	 */
	 
	private static $_object_cache = array();
	private static $_static_variables = array();

	protected static $_fields = array();
	protected static $_foreign_key;
	protected static $_primary_key = 'id';
	protected static $_table;
	
	protected static $_aliases = array();
	protected static $_belongs_to = array();
	protected static $_has_and_belongs_to_many = array();
	protected static $_has_many = array();
	protected static $_has_one = array();
	protected static $_relationships = array(
		'belongs_to' => array(),
		'has_and_belongs_to_many' => array(),
		'has_many' => array(),
		'has_one' => array(),
	);
	
	protected static $_cache;
	protected static $_cache_expiration;
	protected static $_connection;
	
	public static $validations;

	
	/**
	 * Public Variables
	 */
	 
	public $errors = array();
	 
	 
	/**
	 * Constructor
	 */
	 
	function __construct() {
		$class_name = get_class($this);
		self::_init($class_name);
		$fields = $this->fields();
		foreach ($fields as $field) $this->_field_values[$field] = null;
		
		if (method_exists($this, '_before_create')) {
			$this->_before_create();
		}
	}
	
	
	/**
	 * Private Functions
	 */
	
	private function _rel_belongs_to($property, $params = array(), $method = 'find_one') {
		$class_name = get_class($this);
		$relationships = self::_get_static($class_name, '_relationships');
		$info = $relationships['belongs_to'][$property];
		$foreign_key = $info['foreign_key'];
		$foreign_key_field = $info['foreign_key_field'];
		$class = $info['class'];
		
		if (empty($this->$foreign_key)) {
			return null;
		}

		self::_require_model($class);
		if (!is_array($params)) $params = array();
		if (empty($params['conditions'])) $params['conditions'] = array();
		$params = self::_single_relationship_params($info, $params);
		$params['conditions'][$foreign_key_field] = $this->$foreign_key;
		return call_user_func(array($class, $method), $params);
	}
	
	private function _rel_has_and_belongs_to_many($property, $params = array(), $method = 'find') {
		$class_name = get_class($this);
		$connection = self::_get_connection($class_name);
		$relationships = self::_get_static($class_name, '_relationships');
		$info = $relationships['has_and_belongs_to_many'][$property];
		$primary_key = self::_get_static($class_name, '_primary_key');
		$primary_key_field = $info['primary_key_field'];
		self::_require_model($info['class']);

		$keyed_ids = $connection->find_primary_keys(
			$info['foreign_key'], $info['table'],
			array(
				'conditions' => array(
					$info['primary_key'] => $this->$primary_key_field
				)
			)
		);
		$ids = array();
		foreach ($keyed_ids as $id) $ids[] = $id[$info['foreign_key']];
		
		if (count($ids) == 0) {
			if ($method == 'count') {
				return 0;
			}
			
			return array();
		}
		
		$scalar = is_scalar($params);
		$params = self::_single_relationship_params($info, $params);
		
		if ($scalar) {
			if (!in_array($params, $ids)) {
				if ($method == 'count') {
					return 0;
				}
				
				return null;
			}
			
			if ($method == 'find') $method = 'find_one';
			return call_user_func(array($info['class'], $method), $params);
		}
		
		if (empty($params['order']) && !empty($info['order'])) {
			$params['order'] = $info['order'];
		}

		if (empty($params['conditions'])) $params['conditions'] = array();

		if (!empty($params['conditions']['__primary_key__'])) {
			$pk = $params['conditions']['__primary_key__'];
			if (!is_array($pk)) $pk = array($pk);

			foreach ($pk as $key) {
				if (!in_array($key, $ids)) {
					if ($method == 'count') {
						return 0;
					}
					
					return array();
				}
			}
		}
		
		$params['conditions']['__primary_key__'] = $ids;
		return call_user_func(array($info['class'], $method), $params);
	}
	
	private function _rel_has_many($property, $params = array(), $method = 'find') {
		$class_name = get_class($this);
		$relationships = self::_get_static($class_name, '_relationships');
		$info = $relationships['has_many'][$property];
		$primary_key = self::_get_static($class_name, '_primary_key');
		$primary_key_field = $info['primary_key_field'];
		self::_require_model($info['class']);
		
		$scalar = is_scalar($params);
		$params = self::_single_relationship_params($info, $params);
		if (empty($params['conditions'])) $params['conditions'] = array();
		$params['conditions'][$info['primary_key']] = $this->$primary_key_field;

		if ($scalar) {
			if ($method == 'find') $method = 'find_one';
			return call_user_func(array($info['class'], $method), $params);
		}
		
		if (empty($params['order']) && !empty($info['order'])) {
			$params['order'] = $info['order'];
		}

		return call_user_func(array($info['class'], $method), $params);
	}
	
	private function _rel_has_one($property, $params = array(), $method = 'find_one') {
		$class_name = get_class($this);
		$relationships = self::_get_static($class_name, '_relationships');
		$info = $relationships['has_one'][$property];
		$primary_key = self::_get_static($class_name, '_primary_key');
		$primary_key_field = $info['primary_key_field'];
		self::_require_model($info['class']);
		if (!is_array($params)) $params = array();
		if (empty($params['conditions'])) $params['conditions'] = array();
		$params = self::_single_relationship_params($info, $params);
		$params['conditions'][$info['primary_key']] = $this->$primary_key_field;
		return call_user_func(array($info['class'], $method), $params);
	}
	
	private function _set_is_created() {
		if ($this->_is_created) {
			return;
		}

		if (method_exists($this, '_after_create')) {
			$this->_after_create();
		}
			
		$this->_is_created = true;
	}
	
	// if $hard is true, the object is clean after the values are set
	// if $hard is false, the object is dirty after the values are set
	private function _set_values($data, $hard = false) {
		$class_name = get_class($this);
		$primary_key = self::_get_static($class_name, '_primary_key');
		
		foreach ($data as $field => $value) {
			if (!property_exists($this, $field)) {
				continue;
			}
			
			if ($field == $primary_key && $hard == false) {
				continue;
			}

			$this->$field = $value;
		}
		
		if ($hard == true) {
			$fields = $this->fields();
			
			foreach ($fields as $field) {
				if (array_key_exists($field, $data)) {
					$this->_field_values[$field] = $data[$field];
				} else {
					$this->_field_values[$field] = $this->$field;
				}
			}
		}
	}
	
	
	/**
	 * Static Functions
	 */
	 
	private static function _alias($class_name, $field) {
		$aliases = self::_get_static($class_name, '_aliases');
		return !empty($aliases[$field]) ? $aliases[$field] : $field;
	}
	
	private static function _get_cache($class_name) {
		$cache = self::_get_static($class_name, '_cache');
		
		if (!empty($cache)) {
			return $cache;
		}
		
		return self::$_cache;
	}
	
	private static function _get_cache_expiration($class_name) {
		$expiration = self::_get_static($class_name, '_cache_expiration');
		
		if (!empty($expiration)) {
			return strtotime('+' . $expiration);
		}
		
		$expiration = self::$_cache_expiration;
		
		if (!empty($expiration)) {
			return strtotime('+' . $expiration);
		}
		
		return null;
	}
	
	private static function _get_cache_key($class_name, $id) {
		return 'paragon:' . $class_name . '/' . $id;
	}
	
	private static function _get_connection($class_name) {
		$connection = self::_get_static($class_name, '_connection');
		
		if (!empty($connection)) {
			return $connection;
		}
		
		return self::$_connection;
	}

	private static function _get_data($class_name, $ids) {
		$primary_key = self::_get_static($class_name, '_primary_key');
		$aliases = self::_get_static($class_name, '_aliases');
		$real_primary_key = !empty($aliases[$primary_key]) ? $aliases[$primary_key] : $primary_key;

		$table = self::_get_static($class_name, '_table');
		$cache = self::_get_cache($class_name);
		$connection = self::_get_connection($class_name);
	
		// find data from shared cache
		$ids_to_get = array();
		
		foreach ($ids as $id) {
			$ids_to_get[] = self::_get_cache_key($class_name, $id);
		}
		
		$keyed_data_items = false;
		
		if ($cache != null) $keyed_data_items = $cache->get($ids_to_get);
		if ($keyed_data_items == false) $keyed_data_items = array();
		$data_items = array_values($keyed_data_items);
		
		if (!empty($data_items)) {
			$new_ids = array();
		
			foreach ($ids as $id) {
				$key = self::_get_cache_key($class_name, $id);
				
				if (!empty($keyed_data_items[$key])) {
					continue;
				}
				
				$new_ids[] = $id;
			}
		
			$ids = $new_ids;
			
			if (count($ids) == 0) {
				return $data_items;
			}
		}
	
		// find data from database
		// create the query for remaining items
		$keyed_rows = $connection->find_by_primary_keys($real_primary_key, $table, $ids);
		$rows = array();
		
		foreach ($keyed_rows as $row) {
			$key = self::_get_cache_key($class_name, $row[$real_primary_key]);
			$expiration = self::_get_cache_expiration($class_name);
			if ($cache != null) $cache->set($key, $row, null, $expiration);
			$rows[] = $row;
		}

		return array_merge($data_items, $rows);
	}
	
	private static function _get_instances($class_name, $ids, $index = null) {
		// set some vars
		$cache = self::_get_cache($class_name);
		$runtime_cache = self::$_object_cache[$class_name];
		$unordered_instances = array();
		$ids_to_get = array();

		// get as many instances out of the runtime cache as possible
		foreach ($ids as $id) {
			if (empty($runtime_cache[$id])) {
				$ids_to_get[] = $id;
				continue;
			}
			
			$unordered_instances[$id] = $runtime_cache[$id];
		}
	
		// get instances from the shared cache and/or database
		if (!empty($ids_to_get)) {
			$primary_key = self::_get_static($class_name, '_primary_key');
			$aliases = self::_get_static($class_name, '_aliases');
			$data_items = self::_get_data($class_name, $ids_to_get);

			foreach ($data_items as $data) {
				$fields_to_unset = array();
				
				foreach ($aliases as $field => $real_field) {
					if (array_key_exists($real_field, $data)) {
						$data[$field] = $data[$real_field];
						$fields_to_unset[] = $real_field;
					}
				}
		
				foreach ($fields_to_unset as $field) {
					if (isset($data[$field])) {
						unset($data[$field]);
					}
				}
		
				$instance = self::_new_instance($class_name, $data);
				$id = $instance->$primary_key;
				self::$_object_cache[$class_name][$id] = $instance;
				$unordered_instances[$id] = $instance;
			}
		}

		// put the instances in the right order
		$instances = array();
		
		if ($index != null && !property_exists($class_name, $index)) {
			$index = null;
		}
		
		foreach ($ids as $id) {
			if (empty($unordered_instances[$id])) {
				continue;
			}
			
			$instance = $unordered_instances[$id];

			if ($index != null) {
				$instances[$instance->$index] = $instance;
			} else {
				$instances[] = $instance;
			}
		}

		return $instances;
	}
	
	public static function _get_static($class, $variable) {
		if (!isset(self::$_static_variables[$class])) {
			return null;
		}
		
		if (!isset(self::$_static_variables[$class][$variable])) {
			return null;
		}
		
		return self::$_static_variables[$class][$variable];
	}
	
	private static function _init($class_name) {
		$is_init = self::_get_static($class_name, '_is_init');
		
		if (!empty($is_init)) {
			return;
		}
		
		self::_set_static($class_name, '_is_init', true);
		self::_set_static($class_name, '_class', $class_name);
		$reflection_class = new ReflectionClass($class_name);
		
		// get non-static properties
		$properties = $reflection_class->getProperties();
		$fields = array();
		
		foreach ($properties as $key => $property) {
			if ($property->isPrivate() || $property->isStatic()) {
				continue;
			}
			
			$fields[] = $property->getName();
		}
		
		// fix static properties
		$static_properties = $reflection_class->getStaticProperties();
		$table = $static_properties['_table'];
		
		if (empty($fields) || empty($table)) {
			throw new Exception('Class \'' . $class_name . '\' has been configured improperly.');
		}
		
		self::_set_static($class_name, '_fields', $fields);
		$aliases = !empty($static_properties['_aliases']) ? $static_properties['_aliases'] : array();
		self::_set_static($class_name, '_aliases', $aliases);
		
		$validations = !empty($static_properties['validations']) ? $static_properties['validations'] : array();
		self::_set_static($class_name, 'validations', $validations);

		$primary_key = !empty($static_properties['_primary_key']) ? $static_properties['_primary_key'] : self::$_primary_key;
		self::_set_static($class_name, '_primary_key', $primary_key);
		
		if (!in_array($primary_key, $fields)) {
			throw new Exception('Class \'' . $class_name . '\' does not have the primary key set as a field.');
		}
		
		self::_set_static($class_name, '_table', $table);
		$foreign_key = !empty($static_properties['_foreign_key']) ? $static_properties['_foreign_key'] : ($static_properties['_table'] . '_id');
		self::_set_static($class_name, '_foreign_key', $foreign_key);

		// set relationships
		$relationship_types = array(
			'belongs_to', 'has_and_belongs_to_many', 'has_many', 'has_one'
		);
		$relationships = array();
		
		foreach ($relationship_types as $relationship_type) {
			$relationship = !empty($static_properties['_' . $relationship_type]) ? $static_properties['_' . $relationship_type] : array();
			$relationships[$relationship_type] = array();
		
			foreach ($relationship as $property => $info) {
				if (is_int($property)) {
					$property = $info;
					$info = array();
				}
				
				if (is_string($info)) {
					$class = $info;
					$info = array(
						'class' => $class,
						'type' => $relationship_type,
					);
				}
				
				if (!isset($info['class'])) $info['class'] = self::_translate_property_to_class_name($property);
				if (!isset($info['primary_key'])) $info['primary_key'] = self::_translate_class_name_to_property($class_name) . '_id';
				if (!isset($info['foreign_key'])) $info['foreign_key'] = $property . '_id';

				if (!isset($info['primary_key_field'])) $info['primary_key_field'] = $primary_key;
				if (!isset($info['foreign_key_field'])) $info['foreign_key_field'] = '__primary_key__';

				if (!isset($info['table'])) {
					self::_require_model($info['class']);
					self::_init($info['class']);
					$info['table'] = self::_get_static($info['class'], '_table');
				}
				
				$relationships[$relationship_type][$property] = array(
					'class' => $info['class'],
					'conditions' => !empty($info['conditions']) ? $info['conditions'] : null,
					'foreign_key' => $info['foreign_key'],
					'foreign_key_field' => $info['foreign_key_field'],
					'order' => !empty($info['order']) ? $info['order'] : null,
					'primary_key' => $info['primary_key'],
					'primary_key_field' => $info['primary_key_field'],
					'table' => $info['table'],
				);
			}
		}

		self::_set_static($class_name, '_relationships', $relationships);
		
		// create an object cache for this class if one does not exist yet
		if (!isset(self::$_object_cache[$class_name])) {
			self::$_object_cache[$class_name] = array();
		}
		
		// establish database and cache overrides for this class
		if (!empty($static_properties['_cache'])) {
			$cache = $static_properties['_cache'];
		} else {
			$cache = null;
		}

		self::_set_static($class_name, '_cache', $cache);
		$connection = self::_get_connection($class_name);
	
		if (empty($connection)) {
			if (!empty($static_properties['_connection'])) {
				$connection = $static_properties['_connection'];
				self::_set_static($class_name, '_connection', $connection);
			} else {
				throw new Exception('Class \''. $class_name . '\' is missing a datastore connection.');
			}
		}
	}
	
	private static function _new_instance($class_name, $data) {
		$instance = new $class_name();
		$instance->_set_values($data, true);
		$instance->_set_is_created();
		return $instance;
	}
	
	private static function _order($order, $internal) {
		$class_name = get_called_class();
		self::_init($class_name);
		$fields = self::_get_static($class_name, '_fields');
		$orders = explode(',', $order);
		
		$relationships = self::_get_static($class_name, '_relationships');
		$relationship_data = array();
               
		foreach ($relationships as $type => $classes) {
			foreach ($classes as $relationship => $class) {
				$relationship_data[$relationship] = $class;
			}
		}
		
		$aliases = self::_get_static($class_name, '_aliases');
		$return_order_parts = array();

		foreach ($orders as $key => $order) {
			$order_parts = explode(' ', trim($order), 2);
			$order = trim($order_parts[0]);
			
			if (!empty($order_parts[1])) {
				$modifier = strtolower(trim($order_parts[1]));
			} else {
				$modifier = '';
			}
			
			$reverse_order = false;
			
			if (substr($order, 0, 1) == '-') {
				$reverse_order = true;
				$order = substr($order, 1);
			}
			
			if (!in_array($order, $fields)) {
				$no_continue = false;
				
				foreach ($relationship_data as $relationship => $data) {
					$prefix1 = $relationship . '_';
					$prefix2 = $relationship . '.';
				
					if (strpos($order, $prefix1) === 0 || strpos($order, $prefix2) === 0) {
						$no_continue = true;
						break;
					}
				}
				
				if (!$no_continue) {
					continue;
				}
			}

			if ($internal) {
				if (!empty($aliases[$order])) {
					$order = $aliases[$order];
				}
			}
			
			if ($modifier == 'desc') {
				$reverse_order = true;
			}
			
			if (!empty($reverse_order)) {
				$order .= ' DESC';
			}
			
			$return_order_parts[] = $order;
		}
		
		$return_order = implode(', ', $return_order_parts);
		return $return_order;
	}
	
	private static function _relationship_params($class_name, $params) {
		$table = self::_get_static($class_name, '_table');
		$tables = array();
		$tables[$table . '_primary'] = array(
			'table' => $table,
			'type' => 'primary',
		);
		$extra_tables = array();
		$fields = self::_get_static($class_name, '_fields');
		
		if (!empty($params['order'])) {
			$order_parts = explode(', ', $params['order']);
		}
		
		if (!empty($params['conditions']) || !empty($params['order'])) {
			$relationships = self::_get_static($class_name, '_relationships');
			$relationship_data = array();
		
			foreach ($relationships as $type => $classes) {
				foreach ($classes as $relationship => $class) {
					$class['type'] = $type;
					$relationship_data[$relationship] = $class;
				}
			}

			$found_relationships = array();
		
			foreach ($relationship_data as $relationship => $data) {
				if (!empty($params['conditions'])) {
					foreach ($params['conditions'] as $key => $val) {
						$prefix1 = $relationship . '_';
						$prefix2 = $relationship . '.';
					
						if (
							(
								strpos($key, $prefix1) === 0
								&& !in_array($key, $fields)
							) || strpos($key, $prefix2) === 0
						) {
							if (in_array($key, $fields)) {
								continue;
							}
						
							if (empty($found_relationships[$relationship])) {
								$found_relationships[$relationship] = array(
									'conditions' => array(),
									'order' => array(),
								);
							}
							
							$found_relationships[$relationship]['conditions'][$key] = $val;
						}
					}
				}
				
				if (!empty($params['order'])) {
					foreach ($order_parts as $key => $order) {
						$prefix1 = $relationship . '_';
						$prefix2 = $relationship . '.';
						$order_field = $order;
						
						if (strpos($order, ' ')) {
							$order_field = substr($order, 0, strpos($order, ' '));
						}
					
						if (
							(
								strpos($order, $prefix1) === 0
								&& !in_array($order, $fields)
							) || strpos($order, $prefix2) === 0
						) {
							if (empty($found_relationships[$relationship])) {
								$found_relationships[$relationship] = array(
									'conditions' => array(),
									'order' => array(),
								);
							}
							
							$found_relationships[$relationship]['order'][$key] = $order;
						}
					}
				}
			}

			foreach ($found_relationships as $relationship_key => $matches) {
				$relationship = $relationship_data[$relationship_key];
				
				foreach ($matches['conditions'] as $key => $data) {
					$field = substr($key, strlen($relationship_key) + 1);
					$field = self::_alias($relationship['class'], $field);
					unset($params['conditions'][$key]);
					
					if ($relationship['type'] == 'has_and_belongs_to_many') {
						self::_init($relationship['class']);
						$other_table = self::_get_static($relationship['class'], '_table');
						$other_primary_key = self::_get_static($relationship['class'], '_primary_key');
						$real_key = self::_alias($relationship['class'], $field);
						$field = $real_key;
					}
					
					if (strpos($field, '.')) {
						list($these_tables, $these_params) = self::_relationship_params($relationship['class'], array(
							'conditions' => array(
								$field => $data,
							),
						));
						
						foreach ($these_params['conditions'] as $condition => $value) {
							$params['conditions'][$condition] = $value;
						}
						
						foreach ($these_tables as $this_table => $table_info) {
							if ($table_info['type'] == 'primary') {
								continue;
							}
							
							if (!empty($extra_tables[$this_table])) {
								continue;
							}

							if (empty($table_info['intermediary_table'])) {
								$table_info['intermediary_table'] = $relationship_key;
							}
							
							$extra_tables[$this_table] = $table_info;
						}
					} else {
						$params['conditions'][$relationship_key . '.' . $field] = $data;
					}
				}
				
				foreach ($matches['order'] as $key => $order) {
					$order_field = $order;
					$suffix = '';
					
					if (strpos($order, ' ')) {
						$order_field = substr($order, 0, strpos($order, ' '));
						$suffix = substr($order, strpos($order, ' '));
					}
					
					$field = substr($order_field, strlen($relationship_key) + 1);
					$field = self::_alias($relationship['class'], $field);
					$order_parts[$key] = $relationship_key . '.' . $field . $suffix;
				}

				$primary_key = self::_get_static($relationship['class'], '_primary_key');
				$primary_key = self::_alias($class_name, $primary_key);
				$relationship['primary_key'] = self::_alias($relationship['class'], $relationship['primary_key']);
				$relationship['primary_key_field'] = self::_alias($class_name, $relationship['primary_key_field']);
				$relationship['foreign_key'] = self::_alias($relationship['class'], $relationship['foreign_key']);
				
				if ($relationship['type'] == 'belongs_to') {
					$foreign_key = self::_alias($class_name, $relationship['foreign_key']);
					$other_primary_key = self::_get_static($relationship['class'], '_primary_key');
					$other_primary_key = self::_alias($relationship['class'], $other_primary_key);
					$tables[$relationship_key] = array(
						'table' => $relationship['table'],
						'primary_key' => $foreign_key,
						'foreign_key' => $other_primary_key,
						'set_primary_key' => false,
						'type' => 'belongs_to',
					);
				} elseif ($relationship['type'] == 'has_many') {
					$tables[$relationship_key] = array(
						'table' => $relationship['table'],
						'primary_key' => $relationship['primary_key_field'],
						'foreign_key' => $relationship['primary_key'],
						'set_primary_key' => true,
						'type' => 'has_many',
					);
				} elseif ($relationship['type'] == 'has_one') {
					$tables[$relationship_key] = array(
						'table' => $relationship['table'],
						'primary_key' => $primary_key,
						'foreign_key' => $relationship['primary_key'],
						'set_primary_key' => true,
						'type' => 'has_one',
					);
				} elseif ($relationship['type'] == 'has_and_belongs_to_many') {
					$this_primary_key = self::_get_static($class_name, '_primary_key');
					$this_primary_key = self::_alias($class_name, $this_primary_key);
					self::_init($relationship['class']);
					$other_table = self::_get_static($relationship['class'], '_table');
					$other_primary_key = self::_get_static($relationship['class'], '_primary_key');
					$other_primary_key = self::_alias($relationship['class'], $other_primary_key);
					$relationship['primary_key_field'] = self::_alias($relationship['class'], $relationship['primary_key_field']);
					$tables[$relationship_key . '_join'] = array(
						'table' => $relationship['table'],
						'primary_key' => $relationship['primary_key_field'], 
						'foreign_key' => $relationship['primary_key'],
						'set_primary_key' => false,
						'type' => 'has_and_belongs_to_many',
					);
					$tables[$relationship_key] = array(
						'table' => $other_table,
						'primary_key' => $relationship['foreign_key'],
						'foreign_key' => $other_primary_key,
						'intermediary_table' => $relationship_key . '_join',
						'set_primary_key' => true,
						'type' => 'has_and_belongs_to_many',
					);
				}
			}
		}
		
		foreach ($extra_tables as $this_table => $info) {
			if (!empty($tables[$this_table])) {
				continue;
			}
			
			$tables[$this_table] = $info;
		}
		
		if (!empty($order_parts)) {
			foreach ($order_parts as $key => $order) {
				if (!strpos($order, '.')) {
					$order_parts[$key] = $table . '_primary.' . $order;
				}
			}
			
			$params['order'] = implode(', ', $order_parts);
		}

		return array($tables, $params);
	}
	
	private static function _require_model($class) {
		if (class_exists($class)) {
			return;
		}
		
		$filename = self::_translate_class_name_to_property($class);
		$filename = dirname(__FILE__) . '/../../../models/' . $filename . '.php';
		$filename = realpath($filename);
		require_once $filename;
	}
	
	private static function _set_static($class, $variable, $value) {
		self::$_static_variables[$class][$variable] = $value;
		return $value;
	}
	
	private function _single_relationship_params($relationship, $params) {
		if (is_scalar($params)) {
			$params = array(
				'conditions' => array('__primary_key__' => $params)
			);
		}
		
		if (empty($params['conditions'])) $params['conditions'] = array();
	
		if (!empty($relationship['conditions'])) {
			$params['conditions'] = array_merge($relationship['conditions'], $params['conditions']);
		}

		if (!empty($relationship['order']) && empty($params['order'])) {
			$params['order'] = $relationship['order'];
		}
		
		return $params;
	}
	
	private static function _translate_class_name_to_property($class_name) {
		$property = '';

		for ($i = 0; $i < strlen($class_name); $i++) {
			$letter = $class_name{$i};

			if (strtolower($letter) !== $letter) {
				if ($i != 0) $property .= '_';
				$letter = strtolower($letter);
			}

			$property .= $letter;
		}
		
		return $property;
	}
	
	private static function _translate_property_to_class_name($property) {
		return str_replace(' ', '', ucwords(str_replace(array('_', ' '), ' ', $property)));
	}
	
	public static function all_to_json($objects) {
		$array = array();
		
		foreach ($objects as $key => $object) {
			$array[$key] = $object->__toArray();
		}
		
		return json_encode($array);
	}
	
	public static function condition($type, $value) {
		return new ParagonCondition($type, $value);
	}
	
	public static function count($params) {
		if (empty($params)) {
			return 0;
		}

		$class_name = get_called_class();
		self::_init($class_name);
		$primary_key = self::_get_static($class_name, '_primary_key');
		$table = self::_get_static($class_name, '_table');
		$connection = self::_get_connection($class_name);
		$aliases = self::_get_static($class_name, '_aliases');
		
		// if a condition is called '__primary_key__' then replace it with the primary key
		if (
			isset($params['conditions'])
			&& isset($params['conditions']['__primary_key__'])
			&& !isset($params['conditions'][$primary_key])
		) {
			$params['conditions'][$primary_key] = $params['conditions']['__primary_key__'];
			unset($params['conditions']['__primary_key__']);
		}

		foreach ($aliases as $field => $new_field) {
			if (empty($params['conditions']) || !array_key_exists($field, $params['conditions'])) {
				continue;
			}
			
			$params['conditions'][$new_field] = $params['conditions'][$field];
			unset($params['conditions'][$field]);
		}

		list($tables, $params) = self::_relationship_params($class_name, $params);
		$primary_key = self::_alias($class_name, $primary_key);
		return $connection->count($primary_key, $tables, $params);
	}
	
	public static function find($params) {
		// if we have no params, return an empty array for an empty array input or null for anything other input
		if (empty($params)) {
			return is_array($params) ? array() : null;
		}
		
		// set some userful variables
		$class_name = get_called_class();
		self::_init($class_name);
		$primary_key = self::_get_static($class_name, '_primary_key');

		// if we a scalar value, it's probably an id,
		// so return the first result by this id
		if (is_scalar($params)) {
			$instances = self::_get_instances($class_name, array($params));
			return !empty($instances) ? $instances[0] : null;
		}
		
		if (!empty($params[0]) && (int) $params[0] > 0) {
			return self::_get_instances($class_name, $params);
		}
		
		// if a condition is called '__primary_key__' then replace it with the primary key
		if (
			isset($params['conditions'])
			&& isset($params['conditions']['__primary_key__'])
			&& !isset($params['conditions'][$primary_key])
		) {
			$params['conditions'][$primary_key] = $params['conditions']['__primary_key__'];
			unset($params['conditions']['__primary_key__']);
		}
		
		// if we have a params array with only one condition in it,
		// and the condition is the primary key,
		// we have shortcuts to try
		if (
			isset($params['conditions'])
			&& count($params['conditions']) == 1
			&& isset($params['conditions'][$primary_key])
		) {
			$return_array = (count($params) > 1);
			
			// if the primary key is an array containing only one item,
			// then replace it with the value of the item
			// and make sure we return an array
			if (
				is_array($params['conditions'][$primary_key])
				&& count($params['conditions'][$primary_key]) == 1
			) {
				$params['conditions'][$primary_key] = $params['conditions'][$primary_key][0];
				$return_array = true;
			}
			
			// if we have a params array specifying an order,
			// the primary key is scalar,
			// then the order doesn't matter and we can remove it
			if (
				isset($params['order'])
				&& is_scalar($params['conditions'][$primary_key])
			) {
				unset($params['order']);
			}
			
			// if there is only one param,
			// and if the primary key is scalar,
			// pretend we just passed in an id
			if (
				count($params) == 1
				&& is_scalar($params['conditions'][$primary_key])
			) {
				$instance = call_user_func(array($class_name, 'find'), $params['conditions'][$primary_key]);

				if ($return_array && !empty($instance)) {
					return array($instance);
				}
				
				return $instance;
			}
		}

		if (
			isset($params['conditions'])
			&& count($params['conditions']) == 1
			&& isset($params['conditions'][$primary_key])
			&& !isset($params['limit'])
			&& !isset($params['offset'])
			&& !isset($params['order'])
		) {
			$primary_keys = $params['conditions'][$primary_key];
			if (is_scalar($primary_keys)) $primary_keys = array($primary_keys);
		} else {
			$primary_keys = self::find_primary_keys($params);
		}
		
		if (empty($primary_keys)) {
			return array();
		}

		$index = !empty($params['index']) ? $params['index'] : null;
		return self::_get_instances($class_name, $primary_keys, $index);
	}
	
	public static function find_one($params) {
		$params['limit'] = 1;
		$class_name = get_called_class();
		$instances = call_user_func(array($class_name, 'find'), $params);
		return !empty($instances) ? $instances[0] : null;
	}
	
	public static function find_primary_keys($params) {
		// set some userful variables
		$class_name = get_called_class();
		self::_init($class_name);
		$connection = self::_get_connection($class_name);
		$primary_key = self::_get_static($class_name, '_primary_key');
		$table = self::_get_static($class_name, '_table');
		$aliases = self::_get_static($class_name, '_aliases');
		
		// if a condition is called '__primary_key__' then replace it with the primary key
		if (
			isset($params['conditions'])
			&& isset($params['conditions']['__primary_key__'])
			&& !isset($params['conditions'][$primary_key])
		) {
			$params['conditions'][$primary_key] = $params['conditions']['__primary_key__'];
			unset($params['conditions']['__primary_key__']);
		}

		foreach ($aliases as $field => $new_field) {
			if (empty($params['conditions']) || !array_key_exists($field, $params['conditions'])) {
				continue;
			}
			
			$params['conditions'][$new_field] = $params['conditions'][$field];
			unset($params['conditions'][$field]);
		}
		
		if (isset($params['order'])) {
			$params['order'] = self::_order($params['order'], true);
		}

		$primary_keys = array();
		$real_primary_key = !empty($aliases[$primary_key]) ? $aliases[$primary_key] : $primary_key;
		list($tables, $params) = self::_relationship_params($class_name, $params);
		$results = $connection->find_primary_keys($real_primary_key, $tables, $params);
		
		foreach ($results as $key => $val) {
			$primary_keys[] = $val[$real_primary_key];
		}

		return $primary_keys;
	}
	
	public static function get_cache() {
		$class_name = get_called_class();
		return self::_get_cache($class_name);
	}
	
	public static function order($order) {
		return self::_order($order, false);
	}
	
	public static function paginate($params = array()) {
		$class_name = get_called_class();
		$conditions = !empty($params['conditions']) ? $params['conditions'] : array();
		$count = call_user_func(array($class_name, 'count'), array('conditions' => $conditions));
		
		if (!empty($params['page']) && $params['page'] > 0) {
			$page = $params['page'];
		} else {
			$page = 1;
		}
		
		if (!empty($params['per_page']) && $params['per_page'] > 0) {
			$per_page = $params['per_page'];
		} else {
			$per_page = 10;
		}

		$pages = ceil($count / $per_page);
		if ($page > $pages) $page = $pages;
		$start = ($count > 0) ? 1 + ($page * $per_page) - $per_page : 0;
		$end = ($page == $pages) ? $count : $page * $per_page;
		$range = $start . '-' . $end;
		$params['limit'] = $per_page;
		$params['offset'] = ($start > 0) ? $start - 1 : 0;
		
		if ($count > 0) {
			$data = call_user_func(array($class_name, 'find'), $params);
		} else {
			$data = array();
		}

		$pagination = array(
			'count' => $count,
			'end' => $end,
			'page' => $page,
			'pages' => $pages,
			'per_page' => $per_page,
			'range' => $range,
			'start' => $start,
		);
		return array($data, $pagination);
	}
	
	public static function get_connection() {
		$class_name = get_called_class();
		return self::_get_connection($class_name);
	}
	
	public static function set_cache($cache, $cache_expiration = null) {
		$class_name = get_called_class();
		
		if ($class_name == 'Paragon') {
			self::$_cache = $cache;
			self::$_cache_expiration = $cache_expiration;
			return;
		}
		
		self::_set_static($class_name, '_cache', $cache);
		self::_set_static($class_name, '_cache_expiration', $cache_expiration);
	}
	
	public static function set_connection($connection) {
		$class_name = get_called_class();
		
		if ($class_name == 'Paragon') {
			self::$_connection = $connection;
			return;
		}
		
		self::_set_static($class_name, '_connection', $connection);
	}
	
	public static function uncache_all() {
		$class_name = get_called_class();
		
		// reset runtime cache
		if ($class_name == 'Paragon') {
			foreach (self::$_object_cache as $class => $cache) {
				self::$_object_cache[$class] = array();
			}

			return;
		}

		// reset in runtime cache for this class only
		self::$_object_cache[$class_name] = array();
	}
	
	
	/**
	 * Public Functions
	 */
	 
	public function __call($function, $args = null) {
		if (empty($args)) {
			return self::__get($function);
		}
		
		$class_name = get_class($this);
		$primary_key = self::_get_static($class_name, '_primary_key');
		$relationships = self::_get_static($class_name, '_relationships');

		if (isset($relationships['belongs_to'][$function])) {
			$method = '_rel_belongs_to';
			$relationship = $relationships['belongs_to'][$function];
		} elseif (isset($relationships['has_and_belongs_to_many'][$function])) {
			$method = '_rel_has_and_belongs_to_many';
			$relationship = $relationships['has_and_belongs_to_many'][$function];
		} elseif (isset($relationships['has_many'][$function])) {
			$method = '_rel_has_many';
			$relationship = $relationships['has_many'][$function];
		} elseif (isset($relationships['has_one'][$function])) {
			$method = '_rel_has_one';
			$relationship = $relationships['has_one'][$function];
		}
		
		if (isset($relationship)) {
			if (!isset($this->$primary_key)) {
				return null;
			}
			
			$params = $args[0];
			
			if (
				is_array($params) && !empty($params['conditions'])
				&& array_key_exists($relationship['foreign_key'], $params['conditions'])
			) {
				$params['conditions']['__primary_key__'] = $params['conditions'][$relationship['foreign_key']];
				unset($params['conditions'][$relationship['foreign_key']]);
			}
			
			return call_user_func(array($this, $method), $function, $params);
		}

		throw new Exception('Function \'' . $function . '\' not found in \'' . $class_name . '\'.');
	}

	public function __get($property) {
		if (strpos($property, '.')) {
			$relationship = substr($property, 0, strpos($property, '.'));
			$field = substr($property, strpos($property, '.') + 1);
			$property = $relationship;
		}

		if (isset($this->_relationship_instances[$property])) {
			$instance = $this->_relationship_instances[$property];
			
			if (!empty($field)) {
				return ($instance != null && !is_array($instance)) ? $instance->$field : null;
			}
			
			return $instance;
		}
		
		$class_name = get_class($this);
		$primary_key = self::_get_static($class_name, '_primary_key');

		if ($property === '__primary_key__') {
			return $this->$primary_key;
		}
	
		$relationships = self::_get_static($class_name, '_relationships');

		if (isset($relationships['belongs_to'][$property])) {
			$instance = $this->_rel_belongs_to($property);
			$this->_relationship_instances[$property] = $instance;
			
			if (!empty($field)) {
				return ($instance != null) ? $instance->$field : null;
			}
			
			return $instance;
		}
		
		if (isset($relationships['has_and_belongs_to_many'][$property])) {
			if (!isset($this->$primary_key)) {
				return array();
			}
			
			$instances = $this->_rel_has_and_belongs_to_many($property);
			$this->_relationship_instances[$property] = $instances;
			return $instances;
		}
		
		if (isset($relationships['has_many'][$property])) {
			if (!isset($this->$primary_key)) {
				return array();
			}
			
			$instances = $this->_rel_has_many($property);
			$this->_relationship_instances[$property] = $instances;
			return $instances;
		}
		
		if (isset($relationships['has_one'][$property])) {
			if (!isset($this->$primary_key)) {
				return null;
			}
			
			$instance = $this->_rel_has_one($property);
			$this->_relationship_instances[$property] = $instance;
			
			if (!empty($field)) {
				return ($instance != null) ? $instance->$field : null;
			}
			
			return $instance;
		}

		return null;
	}
	
	public function __toArray() {
		$class_name = get_class($this);
		$array = array();
		$fields = $this->fields();
		$validations = self::_get_static($class_name, 'validations');
		
		foreach ($fields as $field) {
			if (!empty($validations[$field]) && !empty($validations[$field]['private'])) {
				continue;
			}
		
			$array[$field] = $this->$field;
		}
		
		return $array;
	}
	
	public function add_relationship($property, $instance) {
		$class_name = get_class($this);
		$connection = self::_get_connection($class_name);
		$relationships = self::_get_static($class_name, '_relationships');
		
		if (is_array($property)) {
			$relationship = $property;
			$type = $relationship['type'];
		} else {
			foreach ($relationships as $_type => $_relationship) {
				if (!isset($_relationship[$property])) {
					continue;
				}
				
				$type = $_type;
				$relationship = $_relationship[$property];
				break;
			}
		}
		
		if (empty($type) || empty($relationship)) {
			return;
		}

		if ($type == 'belongs_to') {
			$this->{$relationship['foreign_key']} = $instance->__primary_key__;
			$this->save();
			return;
		}

		if ($type == 'has_and_belongs_to_many') {
			$existing_relationship = call_user_func(array($this, $property), array(
				'conditions' => array(
					$relationship['foreign_key'] => $instance->__primary_key__
				)
			));

			if (!empty($existing_relationship)) {
				return;
			}
		
			$data = array(
				$relationship['primary_key'] => $this->__primary_key__,
				$relationship['foreign_key'] => $instance->__primary_key__,
			);
			$connection->save(null, $relationship['table'], $data, null);
			return;
		}
		
		if ($type == 'has_many') {
			$relationship['type'] = 'belongs_to';
			$instance->add_relationship($relationship, $this);
			return;
		}
		
		if ($type == 'has_one') {
			$relationship['type'] = 'belongs_to';
			$instance->add_relationship($relationship, $this);
			return;
		}
	}
	
	public function remove_relationship($property, $instance) {
		$class_name = get_class($this);
		$connection = self::_get_connection($class_name);
		$relationships = self::_get_static($class_name, '_relationships');
		
		if (is_array($property)) {
			$relationship = $property;
			$type = $relationship['type'];
		} else {
			foreach ($relationships as $_type => $_relationship) {
				if (!isset($_relationship[$property])) {
					continue;
				}
				
				$type = $_type;
				$relationship = $_relationship[$property];
				break;
			}
		}
		
		if (empty($type) || empty($relationship)) {
			return;
		}

		if ($type == 'belongs_to') {
			$this->{$relationship['foreign_key']} = null;
			$this->save();
			return;
		}

		if ($type == 'has_and_belongs_to_many') {
			$primary_keys = array($relationship['primary_key'], $relationship['foreign_key']);
			$table = $relationship['table'];
			$connection->delete_by_primary_keys(
				$primary_keys, $table,
				array(
					array(
						array($this->__primary_key__),
						array($instance->__primary_key__)
					)
				)
			);
			return;
		}
		
		if ($type == 'has_many') {
			$relationship['type'] = 'belongs_to';
			$instance->remove_relationship($relationship, $this);
			return;
		}
		
		if ($type == 'has_one') {
			$relationship['type'] = 'belongs_to';
			$instance->remove_relationship($relationship, $this);
			return;
		}
	}
	
	public function delete() {
		$class_name = get_class($this);
		$connection = self::_get_connection($class_name);
	
		// if no value for the primary key, then nothing to delete
		$primary_key = self::_get_static($class_name, '_primary_key');

		if (empty($this->$primary_key)) {
			return;
		}
		
		$aliases = self::_get_static($class_name, '_aliases');
		$real_primary_key = !empty($aliases[$primary_key]) ? $aliases[$primary_key] : $primary_key;
		
		// clear this object out of the cache
		$this->uncache();
		
		// delete the object
		$table = self::_get_static($class_name, '_table');
		return $connection->delete_by_primary_keys($real_primary_key, $table, array($this->$primary_key));
	}
	
	public function fields() {
		$class_name = get_class($this);
		return self::_get_static($class_name, '_fields');
	}
	
	public function reload() {
		$class_name = get_class($this);
		$primary_key = self::_get_static($class_name, '_primary_key');
		$data_items = self::_get_data($class_name, array($this->$primary_key));
		$data = !empty($data_items[0]) ? $data_items[0] : array();
		$this->_set_values($data, true);
		$this->_set_is_created();
	}
	 
	public function save() {
		$class_name = get_class($this);
		$validations = self::_get_static($class_name, 'validations');
	
		if (!$this->_is_created) {
			$this->date_created = date('Y-m-d H:i:s');
			
			if (
				!empty($validations['date_created'])
				&& is_array($validations['date_created'])
				&& !empty($validations['date_created']['timestamp'])
			) {
				$this->date_created = strtotime($this->date_created);
			}
		}
		
		$this->date_updated = date('Y-m-d H:i:s');
		
		if (
			!empty($validations['date_updated'])
			&& is_array($validations['date_updated'])
			&& !empty($validations['date_updated']['timestamp'])
		) {
			$this->date_updated = strtotime($this->date_updated);
		}
		
		if ($this->validate() != true) {
			return false;
		}
	
		if (method_exists($this, '_before_save')) {
			$this->_before_save();
		}
	
		$class_name = get_class($this);
		$cache = self::_get_cache($class_name);
		$connection = self::_get_connection($class_name);
		$primary_key = self::_get_static($class_name, '_primary_key');
		$table = self::_get_static($class_name, '_table');
		$aliases = self::_get_static($class_name, '_aliases');
		
		// figure out if there were any changes
		$fields = $this->fields();
		$changes = array();

		foreach ($fields as $field) {
			if ($this->$field == $this->_field_values[$field]) {
				continue;
			}
			
			$real_field = $field;
			
			if (array_key_exists($field, $aliases)) {
				$real_field = $aliases[$field];
			}
			
			$changes[$real_field] = $this->$field;
		}
		
		$real_primary_key = !empty($aliases[$primary_key]) ? $aliases[$primary_key] : $primary_key;

		if (!$this->_is_created) {
			$result = $connection->save($real_primary_key, $table, $changes, null);
			
			// if primary key not set, assume auto-increment value and set it
			if (empty($this->$primary_key)) {
				$this->$primary_key = $result;
			}
		} elseif (!empty($changes)) {
			$connection->save($real_primary_key, $table, $changes, $this->$primary_key);
			$cache_key = self::_get_cache_key($class_name, $this->$primary_key);
			if ($cache != null) $cache->delete($cache_key);
		}

		$this->reload();

		if (method_exists($this, '_after_save')) {
			$this->_after_save();
		}
		
		return true;
	}
	
	public function set_values($data, $params = array()) {
		if (!empty($params['booleans'])) {
			foreach ($params['booleans'] as $field) {
				if (!array_key_exists($field, $data)) $data[$field] = false;
			}
		}
		
		if (!empty($params['prefix'])) {
			$new_data = array();

			foreach ($data as $key => $value) {
				if ($params['prefix'] . '_' == substr($key, 0, strlen($params['prefix']) + 1)) {
					$new_data[substr($key, strlen($params['prefix']) + 1)] = $value;
				}
			}

			$data = $new_data;
		}

		$this->_set_values($data, false);
	}
		
	public function to_json() {
		$array = $this->__toArray();
		return json_encode($array);
	}
	
	public function total($function, $conditions = array()) {
		$class_name = get_class($this);
		$primary_key = self::_get_static($class_name, '_primary_key');
		$relationships = self::_get_static($class_name, '_relationships');
	
		if (isset($relationships['belongs_to'][$function])) {
			$method = '_rel_belongs_to';
			$relationship = $relationships['belongs_to'][$function];
		} elseif (isset($relationships['has_and_belongs_to_many'][$function])) {
			$method = '_rel_has_and_belongs_to_many';
			$relationship = $relationships['has_and_belongs_to_many'][$function];
		} elseif (isset($relationships['has_many'][$function])) {
			$method = '_rel_has_many';
			$relationship = $relationships['has_many'][$function];
		} elseif (isset($relationships['has_one'][$function])) {
			$method = '_rel_has_one';
			$relationship = $relationships['has_one'][$function];
		}
		
		if (!isset($relationship) || !isset($this->$primary_key)) {
			return 0;
		}
		
		if (!empty($conditions)) {
			if (array_key_exists($relationship['foreign_key'], $conditions)) {
				$conditions['__primary_key__'] = $conditions[$relationship['foreign_key']];
				unset($conditions[$relationship['foreign_key']]);
			}
		}

		$params = array('conditions' => $conditions);
		return call_user_func(array($this, $method), $function, $params, 'count');
	}
	
	public function uncache() {
		$class_name = get_class($this);
		$cache = self::_get_cache($class_name);
		$primary_key = self::_get_static($class_name, '_primary_key');
		
		// unset in runtime cache
		unset(self::$_object_cache[$class_name][$this->$primary_key]);
		
		// unset in shared cache
		$cache_key = self::_get_cache_key($class_name, $this->$primary_key);
		if ($cache != null) $cache->delete($cache_key);
	}
	
	public function validate() {
		if (!class_exists('Validator')) {
			return true;
		}
		
		$class_name = get_class($this);
		$fields = $this->fields();
		$validations = self::_get_static($class_name, 'validations');
		$validator = new Validator($this, $validations);
		
		if (!$validator->validate()) {
			$this->errors = $validator->errors;
			return false;
		}
		
		$this->errors = array();
		return true;
	}
}

if (!function_exists('get_called_class')) {
	function get_called_class($deep = 1) {
		$backtrace = debug_backtrace();
		$call_info = $backtrace[$deep];
		
		if (isset($call_info['object']) && isset($call_info['class'])) {
			return $call_info['class'];
		}
		
		if (!isset($call_info['file'])) $call_info = $backtrace[++$deep];
		$func = $call_info['function'];
		
		if ($func == 'call_user_func' || $func == 'call_user_func_array') {
			if (is_array($call_info['args'][0])) {
				if (is_string($call_info['args'][0][0])) {
					return $call_info['args'][0][0];
				}
			}
		}
		
		$class = $call_info['class'];
		$file_lines = file($call_info['file']);
		$line_number = $call_info['line'];
		$type = $call_info['type'];
		$matches = array();
		
		// we need to try up to 100 times because the line number
		// given in the backtrace reflects the end of the function call,
		// and this regex needs to search at the beginning of the function call,
		// and function calls might span more than one line.
		// if the function call is more than 100 lines, this will fail.
		for ($i = 0; $i < 100 && $line_number > 0; $i++) {
			$line_number--;
			preg_match("#([a-zA-Z0-9_]+){$type}{$func}( )*\(#", $file_lines[$line_number], $matches);

			if (count($matches) > 0) {
				$class = trim($matches[1]);
				break;
			}
		}

		if ($class == 'self' || $class == 'parent') {
			return get_called_class($deep + 2);
		}

		return $class;
	}
}

class ParagonCondition {
	public $type;
	public $value;

	public function __construct($type, $value) {
		$this->type = $type;
		$this->value = $value;
	}
}
