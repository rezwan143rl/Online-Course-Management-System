<?php
// Singleton Pattern - DB connection
// we use singleton here because every part of the project needs
// the DB. if we just did "new mysqli()" in every file we would
// end up with like 5 separate connections open at the same time which
// is wasteful. singleton makes sure there is only ever one connection
// and everyone shares it.
//
// it is implemeted in same way as logger class example from slide,,,
// static var. to hold the instance, getInstance() to access it

class Database
{
    // the static var. to hold the obj. 
    private static ?Database $instance = null;

    // the actual mysql connection, public so other files can do
    // Database::getInstance()->conn and then run queries on it
    public mysqli $conn;

    // private constructor 
    // making it private means nobody outside can do "new Database()"
    // the only way TO create DB obj. is through getInstance() below
    private function __construct()
    {
        // connect to mysql  me dont have a password,,,
        $this->conn = new mysqli('localhost', 'root', '', 'course_system');

        if ($this->conn->connect_error) {
            die('DB connection failed: ' . $this->conn->connect_error);
        }

        $this->conn->set_charset('utf8mb4');
    }

    // block cloning too, otherwise someone could do $db2 = clone $db
    // and get around the singleton that way
    private function __clone() {}

    // getInstance() is the only way to instanciate the Database class,,,/ make the object
    // first time: $instance is null so it creates the object and stores it
    // every time after: $instance already has the object so just returns it
    // same object every single time - so singleton ,,, correctly implemented,,,
    public static function getInstance(): Database
    {
        if (self::$instance === null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }
}
?>
