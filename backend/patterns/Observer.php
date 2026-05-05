<?php
// Observer Pattern - Notifications
// used for two things in this project:
//   1. when an instructor posts an announcement, all enrolled
//      students automatically get a notification
//   2. when a student is marked absent, they get an alert
//
// the idea is the instructor code doesnt need to know how
// students get notified. it just fires the event and the
// observers handle it. same structure as WeatherStation from class.

// Observer interface - any class that wants to receive notifications
// has to implement this. basically a contract that says
// "you must have an update() method"
interface Observer
{
    public function update(string $message, int $courseId): void;
}


// CourseNotifier is the Subject
// it keeps a list of observers and when something happens
// it loops through the list and calls update() on each one
// it doesnt know or care what update() does - thats the observers job
class CourseNotifier
{
    // the subscriber list - all Observer objects go in here
    private array $observers = [];

    // add an observer to the list
    public function registerObserver(Observer $observer): void
    {
        $this->observers[] = $observer;
    }

    // remove an observer from the list
    public function unregisterObserver(Observer $observer): void
    {
        $this->observers = array_filter(
            $this->observers,
            fn($o) => $o !== $observer
        );
    }

    // this is the firing method - loops through every registered
    // observer and calls update() on each one
    // thats literally all it does - just calls update() for everyone
    // the actual work (the INSERT) happens inside update() not here
    public function notifyObservers(string $message, int $courseId): void
    {
        foreach ($this->observers as $observer) {
            $observer->update($message, $courseId);
        }
    }
}


// DatabaseNotifier - first concrete observer
// this is what actually creates the notification row in the DB
// one instance per student - each one knows its own student id
// when update() is called it does one INSERT for that student
class DatabaseNotifier implements Observer
{
    private mysqli $db;
    private int    $studentId;

    public function __construct(mysqli $db, int $studentId)
    {
        $this->db        = $db;
        $this->studentId = $studentId;
    }

    // this runs automatically when notifyObservers() fires
    // this INSERT is the notification - this is where it actually happens
    public function update(string $message, int $courseId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO notifications (student_id, course_id, message)
             VALUES (?, ?, ?)"
        );
        $stmt->bind_param('iis', $this->studentId, $courseId, $message);
        $stmt->execute();
        $stmt->close();
    }
}


// AttendanceNotifier - second concrete observer
// same structure as DatabaseNotifier but used specifically
// for absence alerts. shows that one Subject (CourseNotifier)
// can have two completely different Observer types registered
class AttendanceNotifier implements Observer
{
    private mysqli $db;
    private int    $studentId;

    public function __construct(mysqli $db, int $studentId)
    {
        $this->db        = $db;
        $this->studentId = $studentId;
    }

    // same as DatabaseNotifier - INSERT into notifications
    // called automatically when instructor marks student absent
    public function update(string $message, int $courseId): void
    {
        $stmt = $this->db->prepare(
            "INSERT INTO notifications (student_id, course_id, message)
             VALUES (?, ?, ?)"
        );
        $stmt->bind_param('iis', $this->studentId, $courseId, $message);
        $stmt->execute();
        $stmt->close();
    }
}


// helper function called from dashboard.php when announcement is posted
// it does the setup work - finds enrolled students, creates the Subject,
// registers one DatabaseNotifier per student, then fires notifyObservers()
function notifyEnrolledStudents(mysqli $db, int $courseId, string $message): void
{
    // find all students enrolled in this course
    $stmt = $db->prepare(
        "SELECT student_id FROM enrollments WHERE course_id = ?"
    );
    $stmt->bind_param('i', $courseId);
    $stmt->execute();
    $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    // create the Subject
    $subject = new CourseNotifier();

    // register one observer per enrolled student
    // each one holds that students id so update() knows who to notify
    foreach ($rows as $row) {
        $subject->registerObserver(
            new DatabaseNotifier($db, (int)$row['student_id'])
        );
    }

    // fire - this calls update() on every registered observer
    $subject->notifyObservers($message, $courseId);
}
?>
