<?php

namespace ionmvc\packages\db\results\mysql;

use ionmvc\packages\db\classes\mysql\result;

class insert extends result {

	public function insert_id() {
		return mysqli_insert_id( $this->table->db()->resource() );
	}

}

?>