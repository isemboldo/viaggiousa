<?php
declare(strict_types=1);

namespace App\Models;

use PDO;
use DB;

abstract class BaseModel
{
    protected PDO $db;

    public function __construct()
    {
        $this->db = DB::pdo();
    }
}
