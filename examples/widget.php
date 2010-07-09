<?php
class Widget extends Paragon {
	protected static $_table = 'widgets';

	public $id;
	
	public $date_created;
	public $date_updated;
	
	public $name;
	public $description;
}
