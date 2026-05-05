<?php
// Proxy Pattern - Course access control
// before a student can see a course, three things have to be true:
//   1. they must be enrolled
//   2. they must have passed the prerequisite course (if there is one)
//   3. their attendance must be 75% or above
//
// CourseProxy handles all three checks. if any fails it blocks access
// and returns a reason. RealCourse just fetches data, no checks at all.
//
// from the Internet example in class - ProxyInternet checked the banned
// sites list before letting RealInternet connect. same idea here.

require_once __DIR__ . '/Database.php';


// CourseGateway - interface that both RealCourse and CourseProxy implement
// dashboard.php talks to this interface so it cant tell if it got
// the real object or the proxy - thats how proxy is supposed to work
interface CourseGateway
{
    public function getCourse(int $courseId): ?array;
    public function checkAccess(int $userId, int $courseId): array;
}


// RealCourse - just fetches the course data from DB
// no access checks here at all, thats the proxys job
// same as RealInternet which just connected without checking anything
class RealCourse implements CourseGateway
{
    private mysqli $db;

    public function __construct()
    {
        $this->db = Database::getInstance()->conn;
    }

    public function getCourse(int $courseId): ?array
    {
        $stmt = $this->db->prepare(
            "SELECT c.*, u.name AS instructor_name
             FROM courses c
             JOIN users u ON c.instructor_id = u.userid
             WHERE c.courseid = ?"
        );
        $stmt->bind_param('i', $courseId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }

    // RealCourse doesnt do any checking, always returns allowed
    public function checkAccess(int $userId, int $courseId): array
    {
        return ['allowed' => true, 'reason' => 'direct access'];
    }
}


// CourseProxy - the gatekeeper
// wraps RealCourse and runs checks before passing through
// checkAccess() is the main method - three sequential checks for students
class CourseProxy implements CourseGateway
{
    private RealCourse $real;
    private mysqli     $db;
    private string     $role;

    public function __construct(string $role)
    {
        $this->real = new RealCourse();
        $this->db   = Database::getInstance()->conn;
        $this->role = $role;
    }

    // this is where the access control logic lives
    // admin bypasses everything, instructor can only see their courses,
    // students go through all three checks
    public function checkAccess(int $userId, int $courseId): array
    {
        // admin sees everything no questions asked
        if ($this->role === 'admin') {
            return ['allowed' => true, 'reason' => 'Admin has full access'];
        }

        // instructor can only access courses they teach
        if ($this->role === 'instructor') {
            $stmt = $this->db->prepare(
                "SELECT courseid FROM courses
                 WHERE courseid = ? AND instructor_id = ?"
            );
            $stmt->bind_param('ii', $courseId, $userId);
            $stmt->execute();
            $found = $stmt->get_result()->num_rows > 0;
            $stmt->close();

            return $found
                ? ['allowed' => true,  'reason' => 'Your course']
                : ['allowed' => false, 'reason' => 'Not your assigned course'];
        }

        // --- student checks start here ---

        // check 1: is the student actually enrolled in this course?
        $stmt = $this->db->prepare(
            "SELECT id FROM enrollments
             WHERE student_id = ? AND course_id = ?"
        );
        $stmt->bind_param('ii', $userId, $courseId);
        $stmt->execute();
        $enrolled = $stmt->get_result()->num_rows > 0;
        $stmt->close();

        if (!$enrolled) {
            return ['allowed' => false, 'reason' => 'Not enrolled in this course'];
        }

        // check 2: did the student pass the prerequisite course?
        // some courses require passing another course first
        $stmt = $this->db->prepare(
            "SELECT p.required_course_id, c.title AS req_title
             FROM prerequisites p
             JOIN courses c ON p.required_course_id = c.courseid
             WHERE p.course_id = ?"
        );
        $stmt->bind_param('i', $courseId);
        $stmt->execute();
        $prereqs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($prereqs as $prereq) {
            $reqId    = $prereq['required_course_id'];
            $reqTitle = $prereq['req_title'];

            // get the students weighted average in the required course
            $stmt = $this->db->prepare(
                "SELECT SUM(g.marks * gi.weight) / SUM(gi.weight) AS avg
                 FROM grades g
                 JOIN grade_items gi ON g.grade_item_id = gi.id
                 WHERE g.student_id = ? AND gi.course_id = ?"
            );
            $stmt->bind_param('ii', $userId, $reqId);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $avg = (float)($row['avg'] ?? 0);

            // need 50% or above to count as passed
            if ($avg < 50) {
                return [
                    'allowed' => false,
                    'reason'  => "Prerequisite not met: must pass '$reqTitle' first "
                                 . "(your score: " . round($avg, 1) . "%)"
                ];
            }
        }

        // check 3: is attendance 75% or above?
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total FROM attendance
             WHERE student_id = ? AND course_id = ?"
        );
        $stmt->bind_param('ii', $userId, $courseId);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        if ($total > 0) {
            $stmt = $this->db->prepare(
                "SELECT COUNT(*) AS present FROM attendance
                 WHERE student_id = ? AND course_id = ? AND status = 'present'"
            );
            $stmt->bind_param('ii', $userId, $courseId);
            $stmt->execute();
            $present = (int)$stmt->get_result()->fetch_assoc()['present'];
            $stmt->close();

            $pct = round(($present / $total) * 100, 1);

            if ($pct < 75) {
                return [
                    'allowed' => false,
                    'reason'  => "Attendance too low: {$pct}% (minimum 75% required)"
                ];
            }
        }

        // all three checks passed - let them through
        return ['allowed' => true, 'reason' => 'Access granted'];
    }

    // getCourse() just delegates to RealCourse
    // dashboard calls checkAccess() first, then getCourse() only if allowed
    public function getCourse(int $courseId): ?array
    {
        return $this->real->getCourse($courseId);
    }

    // helper to get the attendance percentage for display in the UI
    public function getAttendancePct(int $userId, int $courseId): ?float
    {
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS total FROM attendance
             WHERE student_id = ? AND course_id = ?"
        );
        $stmt->bind_param('ii', $userId, $courseId);
        $stmt->execute();
        $total = (int)$stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();

        if ($total === 0) return null;

        $stmt = $this->db->prepare(
            "SELECT COUNT(*) AS present FROM attendance
             WHERE student_id = ? AND course_id = ? AND status = 'present'"
        );
        $stmt->bind_param('ii', $userId, $courseId);
        $stmt->execute();
        $present = (int)$stmt->get_result()->fetch_assoc()['present'];
        $stmt->close();

        return round(($present / $total) * 100, 1);
    }
}
?>
