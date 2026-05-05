<?php
// Factory Pattern - User object creation
// when someone logs in the DB gives back a row with a role string
// like 'student' or 'instructor'. Factory reads that and returns
// the right class. the login page never does "new Student()" directly,
// it always goes through UserFactory::create()
//
// from the Battleship example in class - same idea
// User = Battleship (abstract product)
// Student, Instructor, Admin = Carrier, Destroyer (concrete products)
// UserFactory = the factory that decides which one to create


// User - base class, every user type has these properties
// Student, Instructor, Admin all extend this
class User
{
    protected int    $id;
    protected string $name;
    protected string $email;
    protected string $role;

    public function __construct(array $data)
    {
        $this->id    = (int)($data['userid'] ?? 0);
        $this->name  = $data['name']  ?? '';
        $this->email = $data['email'] ?? '';
        $this->role  = $data['role']  ?? '';
    }

    public function getId():    int    { return $this->id;    }
    public function getName():  string { return $this->name;  }
    public function getEmail(): string { return $this->email; }
    public function getRole():  string { return $this->role;  }

    public function getLabel(): string { return 'User'; }
}


// Student - concrete product, extends User
// has its own getLabel() so the UI shows "Student"
class Student extends User
{
    public function getLabel(): string { return 'Student'; }
}


// Instructor - concrete product
class Instructor extends User
{
    public function getLabel(): string { return 'Instructor'; }
}


// Admin - concrete product
// getLabel shows "Admin / Chairman" since admin creates the courses
class Admin extends User
{
    public function getLabel(): string { return 'Admin / Chairman'; }
}


// UserFactory - the actual factory
// create() is the factory method, it reads the role and returns
// whichever concrete class matches. adding a new role later means
// adding a new class and one case here, nothing else changes
class UserFactory
{
    public static function create(array $data): User
    {
        switch ($data['role']) {
            case 'student':
                return new Student($data);

            case 'instructor':
                return new Instructor($data);

            case 'admin':
                return new Admin($data);

            default:
                throw new InvalidArgumentException(
                    "UserFactory: unknown role '{$data['role']}'"
                );
        }
    }
}
?>
