<?php
// Builder Pattern - Course creation
// creating a course isnt just one INSERT. a full course needs rows
// in three tables: courses, course_sections, grade_items
// Builder separates the steps from the instructions
// Director says what to build, Builder does the actual work
//
// from the Sandwich example in class - same structure
// CourseBlueprint = Sandwich (the thing being built)
// CourseBuilderInterface = SandwichBuilder (the steps)
// StandardCourseBuilder, ShortCourseBuilder = BurgerBuilder, HotdogBuilder
// CourseDirector = Director (controls the order)

require_once __DIR__ . '/Database.php';


// CourseBlueprint - the product, built piece by piece
// starts empty, the builder fills it in step by step
class CourseBlueprint
{
    public string $title        = '';
    public string $description  = '';
    public int    $instructorId = 0;
    public array  $sections     = []; // section titles
    public array  $gradeItems   = []; // each item has name and weight
}


// CourseBuilderInterface - defines the steps available
// both builders implement all of these
interface CourseBuilderInterface
{
    public function setBasicInfo(string $title, string $desc, int $instructorId): void;
    public function addSection(string $title): void;
    public function addGradeItem(string $name, float $weight): void;
    public function getResult(): CourseBlueprint;
    public function reset(): void;
}


// StandardCourseBuilder - builds a full semester course
// accepts every addSection() call so Director can add multiple sections
class StandardCourseBuilder implements CourseBuilderInterface
{
    private CourseBlueprint $product;

    public function __construct()
    {
        $this->product = new CourseBlueprint();
    }

    public function setBasicInfo(string $title, string $desc, int $instructorId): void
    {
        $this->product->title        = $title;
        $this->product->description  = $desc;
        $this->product->instructorId = $instructorId;
    }

    public function addSection(string $title): void
    {
        // standard course keeps all sections the Director adds
        $this->product->sections[] = $title;
    }

    public function addGradeItem(string $name, float $weight): void
    {
        $this->product->gradeItems[] = ['name' => $name, 'weight' => $weight];
    }

    public function getResult(): CourseBlueprint
    {
        return $this->product;
    }

    public function reset(): void
    {
        $this->product = new CourseBlueprint();
    }
}


// ShortCourseBuilder - builds a short workshop style course
// same interface as Standard but only keeps the first section
// Director calls addSection three times but Short ignores the 2nd and 3rd
// same Director, different result - thats the Builder pattern working
class ShortCourseBuilder implements CourseBuilderInterface
{
    private CourseBlueprint $product;

    public function __construct()
    {
        $this->product = new CourseBlueprint();
    }

    public function setBasicInfo(string $title, string $desc, int $instructorId): void
    {
        $this->product->title        = $title;
        $this->product->description  = $desc;
        $this->product->instructorId = $instructorId;
    }

    public function addSection(string $title): void
    {
        // short course only keeps the first section, rest are ignored
        if (empty($this->product->sections)) {
            $this->product->sections[] = $title;
        }
    }

    public function addGradeItem(string $name, float $weight): void
    {
        // short course only has one grade item
        if (empty($this->product->gradeItems)) {
            $this->product->gradeItems[] = ['name' => $name, 'weight' => 1.0];
        }
    }

    public function getResult(): CourseBlueprint
    {
        return $this->product;
    }

    public function reset(): void
    {
        $this->product = new CourseBlueprint();
    }
}


// CourseDirector - controls the order of steps
// Director knows WHAT to build, Builder knows HOW
// buildStandardCourse calls addSection 3 times and addGradeItem 3 times
// buildShortCourse calls each once
// the builder decides what to do with those calls
class CourseDirector
{
    private CourseBuilderInterface $builder;

    public function __construct(CourseBuilderInterface $builder)
    {
        $this->builder = $builder;
    }

    public function setBuilder(CourseBuilderInterface $builder): void
    {
        $this->builder = $builder;
    }

    // builds a standard full semester course - 3 sections, 3 grade items
    public function buildStandardCourse(string $title, string $desc, int $instId): CourseBlueprint
    {
        $this->builder->reset();
        $this->builder->setBasicInfo($title, $desc, $instId);
        $this->builder->addSection('Week 1 - Introduction');
        $this->builder->addSection('Week 2 - Core Concepts');
        $this->builder->addSection('Week 3 - Advanced Topics');
        $this->builder->addGradeItem('Midterm Exam', 0.30);
        $this->builder->addGradeItem('Assignments',  0.20);
        $this->builder->addGradeItem('Final Exam',   0.50);
        return $this->builder->getResult();
    }

    // builds a short course - 1 section, 1 grade item
    public function buildShortCourse(string $title, string $desc, int $instId): CourseBlueprint
    {
        $this->builder->reset();
        $this->builder->setBasicInfo($title, $desc, $instId);
        $this->builder->addSection('Module 1 - Overview');
        $this->builder->addGradeItem('Final Project', 1.0);
        return $this->builder->getResult();
    }
}


// CourseRepository - saves the finished blueprint to the database
// not part of the Builder pattern itself, just handles the INSERTs
// separated so the Builder stays clean and focused on construction
class CourseRepository
{
    private mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->conn;
    }

    public function save(CourseBlueprint $blueprint): int
    {
        // save the course row
        $stmt = $this->db->prepare(
            "INSERT INTO courses (title, description, instructor_id)
             VALUES (?, ?, ?)"
        );
        $stmt->bind_param('ssi', $blueprint->title, $blueprint->description, $blueprint->instructorId);
        $stmt->execute();
        $courseId = (int)$this->db->insert_id;
        $stmt->close();

        // save each section
        foreach ($blueprint->sections as $order => $sectionTitle) {
            $dispOrder = $order + 1;
            $stmt = $this->db->prepare(
                "INSERT INTO course_sections (course_id, title, display_order)
                 VALUES (?, ?, ?)"
            );
            $stmt->bind_param('isi', $courseId, $sectionTitle, $dispOrder);
            $stmt->execute();
            $stmt->close();
        }

        // save each grade item
        foreach ($blueprint->gradeItems as $item) {
            $stmt = $this->db->prepare(
                "INSERT INTO grade_items (course_id, name, weight)
                 VALUES (?, ?, ?)"
            );
            $stmt->bind_param('isd', $courseId, $item['name'], $item['weight']);
            $stmt->execute();
            $stmt->close();
        }

        return $courseId;
    }
}
?>
