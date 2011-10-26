<?php
class Validator {
	/**
	 * Fields
	 */

	private $_data = array();
	private $_error_formats;
	private $_fields = array();
	private $_rules = array();

	public $errors = array();
	public $fields = array();
	
	
	/**
	 * Constructor
	 */

	public function Validator($data, $rules) {
		$this->_set_error_formats();
		$this->_set_rules($rules);
		$this->_set_fields($data);
	}

	
	/**
	 * Private Functions
	 */

	private function _set_error_formats() {
		$this->_error_formats = array(
			'datetime' => '<strong>%s</strong> is not a valid date',
			'email' => '<strong>%s</strong> is not a valid email address',
			'emails' => '<strong>%s</strong> is not a valid list of email address',
			'equal_to' => '<strong>%s</strong> does not match <strong>%s</strong>',
			'exact_length' => '<strong>%s</strong> must be exactly <strong>%s</strong> characters',
			'max' => '<strong>%s</strong> must be less than or equal to <strong>%s</strong>',
			'max_length' => '<strong>%s</strong> must be no more than <strong>%s</strong> characters',
			'min' => '<strong>%s</strong> must be greater than or equal to <strong>%s</strong>',
			'min_length' => '<strong>%s</strong> must be at least <strong>%s</strong> characters',
			'required' => 'Please fill in the <strong>%s</strong> field',
			'url' => '<strong>%s</strong> is not a valid URL',
		);
	}
	
	private function _set_fields($data) {
		$this->_data = $data;
		$fields = array();
		
		foreach ($this->_rules as $key => $rule) {
			$field_key = !empty($rule['name']) ? $rule['name'] : $key;
			
			if (is_array($this->_data)) {
				if (!array_key_exists($field_key, $this->_data)) {
					$fields[$key] = null;
					continue;
				}
				
				$fields[$key] = $this->_data[$field_key];
			} else {
				if (!property_exists($this->_data, $field_key)) {
					$fields[$key] = null;
					continue;
				}
				
				$fields[$key] = $this->_data->$field_key;
			}

		}

		$this->fields = $fields;
	}

	private function _set_rules($rules) {
		if (empty($rules) || !is_array($rules)) {
			return;
		}
		
		$this->_rules = array();

		foreach ($rules as $field => $rule) {
			$this->_rules[$field] = $rule;
			if (empty($this->fields[$field])) $this->fields[$field] = '';
			
			if (empty($rule['label'])) {
				$this->_rules[$field]['label'] = ucwords(str_replace(array('-', '_'), ' ', $field));
			}
		}
	}
	
	
	/**
	 * Validation Functions
	 */
	
	public static function check_datetime($date) {
		if (empty($date)) {
			return false;
		}
		
		if (strtotime($date) == false) {
			return false;
		}
		
		return true;
	}

	public static function check_email($email) {
		if (empty($email)) {
			return false;
		}
		
		$email = trim($email);
		return (preg_match('/^[A-Za-z0-9\-\.\_\+]+@[A-Za-z0-9\-\.]+\.[A-Za-z0-9\-\.]{2,}$/', $email) > 0);
	}
	
	public static function check_emails($emails) {
		if (empty($emails)) {
			return false;
		}
		
		$emails = explode(',', $emails);
		
		foreach ($emails as $email) {
			if (self::check_email($email)) {
				continue;
			}
			
			return false;
		}
		
		return true;
	}
	
	public static function check_equal_to($val1, $val2) {
		return ($val1 == $val2);
	}
	
	public static function check_exact_length($value, $length) {
		return (strlen($value) == $length);
	}
	
	public static function check_max($value, $max) {
		return (((float) $value) <= $max);
	}
	
	public static function check_max_length($value, $length) {
		return (strlen($value) <= $length);
	}
	
	public static function check_min($value, $min) {
		return (((float) $value) >= $min);
	}
	
	public static function check_min_length($value, $length) {
		return (strlen($value) >= $length);
	}

	public static function check_url($url) {
		if (empty($url)) {
			return false;
		}
		
		return (preg_match("/^[a-zA-Z]+[:\/\/]+[A-Za-z0-9\-_]+\\.+[A-Za-z0-9\.\/%~&=\?\-_]+$/i",$url));
	}


	/**
	 * Public Functions
	 */

	public function validate($rules = null) {
		$this->_set_rules($rules);
		$this->_set_fields($this->_data);

		foreach ($this->_rules as $field => $params) {
			$value = isset($this->fields[$field]) ? $this->fields[$field] : '';
			$label = !empty($params['label']) ? $params['label'] : $field;
			$messages = array_merge($this->_error_formats, !empty($params['messages']) ? $params['messages'] : array());
			
			if (!empty($params['required'])) {
				if ($value == '') {
					$this->errors[$field] = sprintf($messages['required'], $label);
					continue;
				}
			}
			
			if (!empty($params['date']) || !empty($params['datetime'])) {
				if (self::check_datetime($value) == false) {
					$this->errors[$field] = sprintf($messages['datetime'], $value);
					continue;
				}
			}
			
			if (!empty($params['email'])) {
				if (self::check_email($value) == false) {
					$this->errors[$field] = sprintf($messages['email'], $value);
					continue;
				}
			}
			
			if (!empty($params['emails'])) {
				if (self::check_emails($value) == false) {
					$this->errors[$field] = sprintf($messages['emails'], $value);
					continue;
				}
			}
			
			if (!empty($params['equal_to'])) {
				if (isset($this->_rules[$params['equal_to']]) && self::check_equal_to($value, $this->fields[$params['equal_to']])) {
					$this->errors[$field] = sprintf($messages['equal_to'], $label, $this->_rules[$params['equal_to']]['label']);
					continue;
				}
			}

			if (!empty($params['exact_length'])) {
				if (self::check_exact_length($value, $params['exact_length']) == false) {
					$this->errors[$field] = sprintf($messages['exact_length'], $label, $params['exact_length']);
					continue;
				}
			}
			
			if (isset($params['max'])) {
				if (self::check_max($value, $params['max']) == false) {
					$this->errors[$field] = sprintf($messages['max'], $label, $params['max']);
					continue;
				}
			}

			if (!empty($params['max_length'])) {
				if (self::check_max_length($value, $params['max_length']) == false) {
					$this->errors[$field] = sprintf($messages['max_length'], $label, $params['max_length']);
					continue;
				}
			}
			
			if (isset($params['min'])) {
				if (self::check_min($value, $params['min']) == false) {
					$this->errors[$field] = sprintf($messages['min'], $label, $params['min']);
					continue;
				}
			}

			if (isset($params['min_length'])) {
				if (self::check_min_length($value, $params['min_length']) == false) {
					$this->errors[$field] = sprintf($messages['min_length'], $label, $params['min_length']);
					continue;
				}
			}

			if (!empty($params['url'])) {
				if (self::check_url($value) == false) {
					$this->errors[$field] = sprintf($messages['url'], $value);
					continue;
				}
			}
		}

		foreach ($this->errors as $field => $error) {
			if (!empty($error)) {
				return false;
			}
		}

		return true;
	}
}
