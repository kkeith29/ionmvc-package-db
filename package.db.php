<?php

namespace ionmvc\packages;

use ionmvc\classes\app;
use ionmvc\classes\hook;

class db extends \ionmvc\classes\package {

	const version = '1.0.0';
	const class_type_driver = 'ionmvc.db_driver';

	public function setup() {
		app::hook()->add('db',[
			'position' => hook::position_before,
			'hook'     => 'stop',
			'config'   => [
				'required' => true
			]
		]);
		$this->add_type('driver',[
			'type' => self::class_type_driver,
			'type_config' => [
				'file_prefix' => 'driver'
			],
			'path' => 'drivers'
		]);
	}

	public static function package_info() {
		return [
			'author'      => 'Kyle Keith',
			'version'     => self::version,
			'description' => 'DB handler'
		];
	}

}

?>