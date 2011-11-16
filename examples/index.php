<?php
// include the files
require_once '../paragon.php';
require_once '../paragon_drivers/mysqli_master_slave_driver.php';

// setting the connection
// you only need to do this once in the whole script
$mysqli = new Mysqli('localhost', 'username', 'password', 'database');
$driver = new MysqliMasterSlaveDriver(array(
	'master' => $mysqli,
	'slave' => $mysqli,
));
Paragon::set_connection($driver);

// include a model
require_once 'widget.php';

// find by id
$widget = Widget::find(1);

// find one by conditions
$widgets = Widget::find_one(array(
	'conditions' => array(
		'name' => 'foo',
	),
));

// find by conditions, with limit, order, and start parameters
$widgets = Widget::find(array(
	'conditions' => array(
		'name' => self::condition('like', 'bar'),
	),
	'limit' => 10,
	'order' => 'name',
	'start' => 0,
));

// index widgets by id
$widgets_by_id = Widget::find(array(
	'index' => 'id',
	'order' => 'name',
));

// find widget ids
$widget_ids = Widget::find_primary_keys(array(
	'conditions' => array(
		'name' => 'foo',
	),
));

// save a widget
$widget->name = 'bar';
$widget->save();

// create a new widget
$widget = new Widget();
$widget->name = 'foo';
$widget->description = 'i am a widget';
$widget->save();

// using a widget
echo $widget->id;
echo $widget->name;
echo $widget->date_created;

