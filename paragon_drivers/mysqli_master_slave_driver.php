<?php
/*
paragon/drivers/mysqli_master_slave_driver.php
Copyright (c) 2011 Brandon Goldman
Released under the MIT License.
*/

class MysqliMasterSlaveDriver {
	private $_master;
	private $_slave;

	// keys = [id], values = [[1, 2, 3]]
	// => id in (1, 2, 3)
	
	// keys = [one, two], values = [ [1.1,2.1], [1.2,2.2] ]
	// => (one in (1.1) and two in (2.1))
	//    or (one in (1.2) and two in (2.2))
	
	// keys = [one, two], values =
	// [ [[1.11,1.12],[2.11,2.12]], [[1.21,1.22],[2.21,2.22]] ]
	// => (one in (1.11,1.12) and two in (2.11,2.12))
	//    or (one in (1.21,1.22) and two in (2.21,2.22))

	public function __construct($databases) {
		$this->_master = $databases['master'];
		$this->_slave = $databases['slave'];
	}
	
	private function _create_complex_where($conn, $table, $params) {
		// set the conditions
		if (!isset($params['conditions']) || !is_array($params['conditions'])) $params['conditions'] = array();
		$conditions = array();

		foreach ($params['conditions'] as $field => $val) {
			// if we have an empty array, it means this query is requesting "IN ('')" which never returns anything,
			// so just return early with no results
			if (is_array($val) && count($val) == 0) {
				return false;
			}

			if (!is_int($field)) {
				$these_conditions = $this->_where_condition($table, $field, $val);
				
				foreach ($these_conditions as $condition) {
					$conditions[] = $condition;
				}
				
				continue;
			}
			
			if (is_array($val)) {
				$val_conditions = array();
				
				foreach ($val as $k => $v) {
					$these_val_conditions = $this->_where_condition($table, $k, $v);
					
					foreach ($these_val_conditions as $val_condition) {
						$val_conditions[] = $val_condition;
					}
				}
				
				$conditions[] = '(' . implode(' OR ', $val_conditions) . ')';
				continue;
			}

			if (strlen($val) > 0) $conditions[] = $val;
		}

		if (!empty($conditions)) {
			$conditions_string = implode(' AND ', $conditions);
		} else {
			$conditions_string = 1;
		}
		
		$query = ' WHERE ' . $conditions_string;
		return $query;
	}
	
	private function _create_simple_where($conn, $keys, $key_values) {
		$wheres = array();
	
		foreach ($key_values as $key_value) {
			if (count($keys) == 1) {
				$key = $conn->real_escape_string($keys[0]);
				if (!is_array($key_value)) $key_value = array($key_value);
				
				if (count($key_value) == 0) {
					$wheres[] = '`' . $key . '` \'\'';
					continue;
				}

				if (count($key_value) == 1) {
					$value = $conn->real_escape_string($key_value[0]);
					$wheres[] = '`' . $key . '` = \'' . $value . '\'';
					continue;
				}

				$values = array();
				
				foreach ($key_value as $value) {
					$values[] = $conn->real_escape_string($value);
				}
				
				$values_string = '\'' . implode('\',\'', $values) . '\'';
				$wheres[] = '`' . $key . '` IN (' . $values_string . ')';
				continue;
			}
			
			$where = array();
			
			foreach ($keys as $i => $key) {
				$key = $conn->real_escape_string($key);
				$key_value_item = $key_value[$i];
				if (!is_array($key_value_item)) $key_value_item = array($key_value_item);
				
				if (count($key_value_item) == 0) {
					$where[] = '`' . $key . '` = \'\'';
					continue;
				}
				
				if (count($key_value_item) == 1) {
					$value = $key_value_item[0];
					$value = $conn->real_escape_string($value);
					$where[] = '`' . $key . '` = \'' . $value . '\'';
					continue;
				}

				$values = array();
				
				foreach ($key_value_item as $value) {
					$values[] = $conn->real_escape_string($value);
				}
				
				$values_string = '\'' . implode('\',\'', $values) . '\'';
				$where[] = '`' . $key . '` IN (' . $values_string . ')';
			}

			$wheres[] = implode(' AND ', $where);
		}

		$where_string = ' WHERE (' . implode(') OR (', $wheres) . ')';
		return $where_string;
	}

	private function _paragon_condition($val) {
		if ($val->type == 'gt') {
			$predicate = '> \'' . $this->_slave->real_escape_string($val->value) . '\'';
		} elseif ($val->type == 'gte') {
			$predicate = '>= \'' . $this->_slave->real_escape_string($val->value) . '\'';
		} elseif ($val->type == 'like') {
			$predicate = 'LIKE \'%' . $this->_slave->real_escape_string($val->value) . '%\'';
		} elseif ($val->type == 'lt') {
			$predicate = '< \'' . $this->_slave->real_escape_string($val->value) . '\'';
		} elseif ($val->type == 'lte') {
			$predicate = '<= \'' . $this->_slave->real_escape_string($val->value) . '\'';
		} elseif ($val->type == 'not') {
			if (is_array($val->value)) {
				if (count($val->value) == 0) {
					return null;
				}
				
				$vals = array();
		
				foreach ($val->value as $item) {
					$item = $this->_slave->real_escape_string($item);
					$vals[] = '\'' . $item . '\'';
				}
		
				$predicate = 'NOT IN (' . implode(',', $vals) . ')';
			} else {
				$predicate = '!= \'' . $this->_slave->real_escape_string($val->value) . '\'';
			}
		} else {
			return null;
		}
		
		return $predicate;
	}
	
	private function _where_condition($table, $field, $val) {
		$conditions = array();
		
		if (strpos($field, '.') === false) {
			$real_field_name = '' . $table . '.`' . $field. '`';
		} else {
			$real_field_name = $field;
		}

		if ($val === null) {
			$predicate = 'IS NULL';
		} elseif (is_array($val)) {
			if (!empty($val) && is_a($val[0], 'ParagonCondition')) {
				$predicate = array();
				
				foreach ($val as $condition) {
					$predicate_item = $this->_paragon_condition($condition);
					
					if (empty($predicate_item)) {
						return array();
					}
					
					$predicate[] = $predicate_item;
				}
			} else {
				$vals = array();
				
				foreach ($val as $item) {
					$item = $this->_slave->real_escape_string($item);
					$vals[] = '\'' . $item . '\'';
				}
				
				$predicate = 'IN (' . implode(',', $vals) . ')';
			}
		} elseif (is_string($val)) {
			$val = $this->_slave->real_escape_string($val);
			$predicate = '= \'' . $val . '\'';
		} elseif (is_bool($val)) {
			$predicate = '= ' . ($val ? 'true' : 'false');
		} elseif (is_a($val, 'ParagonCondition')) {
			$predicate = $this->_paragon_condition($val);
			
			if (empty($predicate)) {
				return array();
			}
		} else {
			$predicate = '= ' . $val;
		}

		if (!is_array($predicate)) $predicate = array($predicate);
		
		foreach ($predicate as $pred) {
			$conditions[] = $real_field_name . ' ' . $pred;
		}
		
		return $conditions;
	}
		
	public function count($keys, $tables, $params) {
		if (!is_array($tables)) {
			$table = $tables;
			$tables = array(
				$table => array(
					'table' => $table,
					'type' => 'primary',
				),
			);
		}
		
		if (!is_array($keys)) $keys = array($keys);
		
		$tables_string = '';
		$primary_table = null;
		$primary_key = null;
		
		foreach ($tables as $table => $fields) {
			if ($fields['type'] == 'primary') {
				$primary_table = $table;
				$tables_string .= ' ' . $fields['table'] . ' AS ' . $table;
				continue;
			}
			
			if (empty($primary_key) && !empty($fields['set_primary_key'])) {
				$primary_key = reset($keys);
			}
			
			$part = 'LEFT JOIN ' . $fields['table'] . ' AS ' . $table;
			$this_table = !empty($fields['intermediary_table']) ? $fields['intermediary_table'] : $primary_table;
			$part .= ' ON ' . $table . '.' . $fields['foreign_key'] . ' = ' . $this_table . '.' . $fields['primary_key'];
			$tables_string .= ' ' . $part;
		}

		$keys_string = $primary_table . '.`' . implode('`, ' . $primary_table . '.`', $keys) . '`';
		$where_string = $this->_create_complex_where($this->_slave, $primary_table, $params);
		
		$query  = ' SELECT ' . $keys_string
				. ' FROM ' . $tables_string
				.   $where_string;
				
		// add a group by if necessary
		if (count($tables) > 0 && !empty($primary_key)) {
			$query .= ' GROUP BY ' . $primary_table . '.' . $primary_key . ' ';
		}

		// get the result
		$query = "SELECT COUNT(*) AS count FROM (" . $query . ") AS subquery";
		$result = $this->_slave->query($query);
		
		if (!$result) {
			throw new Exception($this->_slave->error . "\r\n" . $query);
		}

		$row = $result->fetch_assoc();
		return !empty($row) ? $row['count'] : 0;
	}
	
	public function delete_by_primary_keys($keys, $table, $key_values) {
		if (!is_array($keys)) {
			$keys = array($keys);
			$key_values = array($key_values);
		}
	
		$table = $this->_master->real_escape_string($table);
		$where_string = $this->_create_simple_where($this->_master, $keys, $key_values);
		$query = 'DELETE FROM ' . $table . $where_string;
		$result = $this->_master->query($query);
		
		if (!$result) {
			throw new Exception($this->_master->error . "\r\n" . $query);
		}
		
		return true;
	}
	
	public function find_by_primary_keys($keys, $tables, $key_values) {
		if (!is_array($tables)) {
			$table = $tables;
			$tables = array(
				$table => array(
					'table' => $table,
					'type' => 'primary',
				),
			);
		}
		
		if (!is_array($keys)) {
			$keys = array($keys);
			$key_values = array($key_values);
		}
	
		$tables_string = '';
		$primary_table = null;

		foreach ($tables as $table => $fields) {
			if ($fields['type'] == 'primary') {
				$primary_table = $table;
				$tables_string .= ' ' . $fields['table'] . ' AS ' . $table;
				continue;
			}
			
			$part = 'LEFT JOIN ' . $fields['table'] . ' AS ' . $table;
			$this_table = !empty($fields['intermediary_table']) ? $fields['intermediary_table'] : $primary_table;
			$part .= ' ON ' . $table . '.' . $fields['foreign_key'] . ' = ' . $this_table . '.' . $fields['primary_key'];
			$tables_string .= ' ' . $part;
		}
		
		$where_string = $this->_create_simple_where($this->_slave, $keys, $key_values);
		$query = 'SELECT * FROM ' . $tables_string . ' ' . $where_string;
		$result = $this->_slave->query($query);
		
		if (!$result) {
			throw new Exception($this->_slave->error . "\r\n" . $query);
		}
		
		$rows = array();

		while ($row = $result->fetch_assoc()) {
			$return_values = array();
			foreach ($keys as $key) $return_values[] = $row[$key];
			$return_values_string = implode('-', $return_values);
			$rows[$return_values_string] = $row;
		}
		
		return $rows;
	}
	
	public function find_primary_keys($keys, $tables, $params) {
		if (!is_array($tables)) {
			$table = $tables;
			$tables = array(
				$table => array(
					'table' => $table,
					'type' => 'primary',
				),
			);
		}
		
		if (!is_array($keys)) $keys = array($keys);
		
		$tables_string = '';
		$primary_table = null;
		$primary_key = null;
		
		foreach ($tables as $table => $fields) {
			if ($fields['type'] == 'primary') {
				$primary_table = $table;
				$tables_string .= ' ' . $fields['table'] . ' AS ' . $table;
				continue;
			}
			
			if (empty($primary_key) && !empty($fields['set_primary_key'])) {
				$primary_key = reset($keys);
			}

			$part = 'LEFT JOIN ' . $fields['table'] . ' AS ' . $table;
			$this_table = !empty($fields['intermediary_table']) ? $fields['intermediary_table'] : $primary_table;
			$part .= ' ON ' . $table . '.' . $fields['foreign_key'] . ' = ' . $this_table . '.' . $fields['primary_key'];
			$tables_string .= ' ' . $part;
		}
		
		$keys_string = $primary_table . '.`' . implode('`, ' . $primary_table . '.`', $keys) . '`';
		$where_string = $this->_create_complex_where($this->_slave, $primary_table, $params);
		
		if ($where_string === false) {
			return array();
		}

		$query  = 'SELECT ' . $keys_string . ' FROM ' . $tables_string . ' ' . $where_string;
		
		// add a group by if necessary
		if (count($tables) > 0 && !empty($primary_key)) {
			$query .= ' GROUP BY ' . $primary_table . '.' . $primary_key . ' ';
		}

		// add order by if necessary
		if (!empty($params['order'])) {
			$order_by = $this->_slave->real_escape_string($params['order']);
			$query .= ' ORDER BY ' . $order_by;
		}

		// add a limit if necessary
		if (isset($params['limit']) || isset($params['offset'])) {
			$offset = isset($params['offset']) ? $params['offset'] : 0;
			$offset = $this->_slave->real_escape_string($offset);
			$query .= ' LIMIT ' . $offset;
		
			if (isset($params['limit'])) {
				$limit = $this->_slave->real_escape_string($params['limit']);
				$query .= ', ' . $limit;
			}
		}
		
		// get the result
		$result = $this->_slave->query($query);
		
		if (!$result) {
			throw new Exception($this->_slave->error . "\r\n" . $query);
		}
		
		// return primary_keys
		$primary_keys = array();

		while ($row = $result->fetch_assoc()) {
			$primary_key = array();
		
			foreach ($keys as $key) {
				$primary_key[$key] = $row[$key];
			}
			
			$primary_keys[] = $primary_key;
		}

		return $primary_keys;
	}
	
	public function save($keys, $table, $changes, $key_values) {
		if (!is_array($keys)) {
			$keys = array($keys);
			$key_values = array($key_values);
		}
		
		$table = $this->_master->real_escape_string($table);

		if (count($key_values) == 1 && $key_values[0] == null) {
			$fields = array();
			$values = array();
		
			foreach ($changes as $field => $value) {
				$fields[] = $this->_master->real_escape_string($field);
				
				if ($value !== null) {
					$value = $this->_master->real_escape_string($value);
					$values[] = '\'' . $value . '\'';
				} else {
					$values[] = 'NULL';
				}
			}
			
			$fields_string = '`' . implode('`,`', $fields) . '`';
			$values_string = implode(',', $values);
		
			$query = ' INSERT INTO ' . $table
				   . ' (' . $fields_string . ')'
				   . ' VALUES'
				   . ' (' . $values_string . ')';
			$result = $this->_master->query($query);
			
			if (!$result) {
				throw new Exception($this->_master->error . "\r\n" . $query);
			}
			
			if (count($keys) == 1 && empty($changes[$keys[0]])) {
				return $this->_master->insert_id;
			}
			
			$key_values = array();
			foreach ($keys as $key) $key_values[] = $changes[$key];
			return implode('-', $key_values);
		}

		$sets = array();
		
		foreach ($changes as $key => $value) {
			$key = $this->_master->real_escape_string($key);
			
			if ($value !== null) {
				$value = $this->_master->real_escape_string($value);
				$value = '\'' . $value . '\'';
			} else {
				$value = 'NULL';
			}
			
			$sets[] = "`$key` = $value";
		}
		
		$set_string = implode(', ', $sets);
		
		$where_string = $this->_create_simple_where($this->_master, $keys, $key_values);
		$query = ' UPDATE ' . $table
			   . ' SET ' . $set_string
			   .   $where_string;
		$result = $this->_master->query($query);
		
		if (!$result) {
			throw new Exception($this->_master->error . "\r\n" . $query);
		}
		
		return true;
	}
}
