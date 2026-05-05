<?php
// dashboard.php - Main application page
// All patterns are wired together here.
// Singleton  -> Database::getInstance()
// Factory    -> UserFactory::create() (used at login, shown via getLabel())
// Observer   -> notifyEnrolledStudents() triggered on announcement/absence
// Decorator  -> buildGradeCalculator() with policy toggles
// Proxy      -> CourseProxy::checkAccess() on every course listed
// Composite  -> CourseStructureLoader::load() for course content tree
// Builder    -> CourseDirector builds courses when admin submits form

session_start();
if (!isset($_SESSION['user'])) {
    header('Location: index.php');
    exit;
}

require_once 'backend/patterns/Database.php';
require_once 'backend/patterns/UserFactory.php';
require_once 'backend/patterns/Observer.php';
require_once 'backend/patterns/Decorator.php';
require_once 'backend/patterns/Proxy.php';
require_once 'backend/patterns/Composite.php';
require_once 'backend/patterns/Builder.php';

// Singleton - one DB connection for this entire page
$db   = Database::getInstance()->conn;
$row  = $_SESSION['user'];
$role = $row['role'];
$uid  = (int)($row['userid'] ?? $row['id'] ?? 0);

// Factory - creates the correct User subclass
// getLabel() shows "Admin / Chairman", "Instructor", or "Student"
$userObj = UserFactory::create($row);

$msg = '';

// ============================================================
// ACTION: ADMIN creates a course (BUILDER pattern)
// ============================================================
if ($role === 'admin' && isset($_POST['create_course'])) {
    $title  = trim($_POST['title']);
    $desc   = trim($_POST['description']);
    $instId = (int)$_POST['instructor_id'];
    $type   = $_POST['course_type'];

    if ($title && $instId) {
        if ($type === 'short') {
            $director = new CourseDirector(new ShortCourseBuilder());
            $blueprint = $director->buildShortCourse($title, $desc, $instId);
        } else {
            $director  = new CourseDirector(new StandardCourseBuilder());
            $blueprint = $director->buildStandardCourse($title, $desc, $instId);
        }

        $repo     = new CourseRepository();
        $courseId = $repo->save($blueprint);

        // Create a default (empty) policy row for this new course
        $stmt = $db->prepare("INSERT IGNORE INTO course_grading_policies (course_id) VALUES (?)");
        $stmt->bind_param('i', $courseId);
        $stmt->execute();
        $stmt->close();

        $msg = "Course created successfully (Builder pattern used).";
    }
}

// ============================================================
// ACTION: ADMIN assigns a prerequisite to a course
// ============================================================
if ($role === 'admin' && isset($_POST['set_prereq'])) {
    $cId  = (int)$_POST['prereq_course_id'];
    $rId  = (int)$_POST['required_course_id'];
    if ($cId && $rId && $cId !== $rId) {
        $db->query("DELETE FROM prerequisites WHERE course_id = $cId");
        $stmt = $db->prepare(
            "INSERT INTO prerequisites (course_id, required_course_id) VALUES (?, ?)"
        );
        $stmt->bind_param('ii', $cId, $rId);
        $stmt->execute();
        $stmt->close();
        $msg = "Prerequisite set.";
    }
}

// ============================================================
// ACTION: ADMIN adds an instructor
// ============================================================
if ($role === 'admin' && isset($_POST['add_instructor'])) {
    $name = trim($_POST['inst_name']);
    $email = trim($_POST['inst_email']);
    $password = trim($_POST['inst_password']);

    if ($name && $email && $password) {
        // Check if email already exists
        $stmt = $db->prepare("SELECT userid FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $msg = "Error: Email already exists.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'instructor')");
            $stmt->bind_param('sss', $name, $email, $hashedPassword);
            $stmt->execute();
            $stmt->close();
            $msg = "Instructor added successfully.";
        }
        $stmt->close();
    } else {
        $msg = "All fields are required.";
    }
}

// ============================================================
// ACTION: ADMIN adds a student
// ============================================================
if ($role === 'admin' && isset($_POST['add_student'])) {
    $name = trim($_POST['stud_name']);
    $email = trim($_POST['stud_email']);
    $password = trim($_POST['stud_password']);

    if ($name && $email && $password) {
        // Check if email already exists
        $stmt = $db->prepare("SELECT userid FROM users WHERE email = ?");
        $stmt->bind_param('s', $email);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $msg = "Error: Email already exists.";
        } else {
            $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $db->prepare("INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, 'student')");
            $stmt->bind_param('sss', $name, $email, $hashedPassword);
            $stmt->execute();
            $stmt->close();
            $msg = "Student added successfully.";
        }
        $stmt->close();
    } else {
        $msg = "All fields are required.";
    }
}

// ============================================================
// ACTION: INSTRUCTOR posts announcement (OBSERVER trigger 1)
// ============================================================
if ($role === 'instructor' && isset($_POST['post_announcement'])) {
    $courseId = (int)$_POST['course_id'];
    $text     = trim($_POST['message']);

    if ($text && $courseId) {
        $stmt = $db->prepare(
            "INSERT INTO announcements (course_id, instructor_id, message) VALUES (?, ?, ?)"
        );
        $stmt->bind_param('iis', $courseId, $uid, $text);
        $stmt->execute();
        $stmt->close();

        notifyEnrolledStudents($db, $courseId, "New announcement: $text");
        $msg = "Announcement posted. All enrolled students notified (Observer pattern).";
    }
}

// ============================================================
// ACTION: INSTRUCTOR marks attendance (OBSERVER trigger 2)
// ============================================================
if ($role === 'instructor' && isset($_POST['mark_attendance'])) {
    $courseId  = (int)$_POST['att_course_id'];
    $studentId = (int)$_POST['att_student_id'];
    $status    = $_POST['att_status'];
    $date      = $_POST['att_date'];

    if ($courseId && $studentId && $date) {
        $stmt = $db->prepare(
            "INSERT INTO attendance (student_id, course_id, class_date, status)
             VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE status = VALUES(status)"
        );
        $stmt->bind_param('iiss', $studentId, $courseId, $date, $status);
        $stmt->execute();
        $stmt->close();

        if ($status === 'absent') {
            $courseName = $db->query(
                "SELECT title FROM courses WHERE courseid = $courseId"
            )->fetch_assoc()['title'];

            $subject = new CourseNotifier();
            $subject->registerObserver(new AttendanceNotifier($db, $studentId));
            $subject->notifyObservers(
                "You were marked absent in '$courseName' on $date.",
                $courseId
            );
        }
        $msg = "Attendance marked. Student notified if absent (Observer pattern).";
    }
}

// ============================================================
// ACTION: INSTRUCTOR enters a grade
// ============================================================
if ($role === 'instructor' && isset($_POST['enter_grade'])) {
    $studentId   = (int)$_POST['grade_student_id'];
    $gradeItemId = (int)$_POST['grade_item_id'];
    $marks       = (float)$_POST['marks'];

    $stmt = $db->prepare(
        "INSERT INTO grades (student_id, grade_item_id, marks)
         VALUES (?, ?, ?)
         ON DUPLICATE KEY UPDATE marks = VALUES(marks)"
    );
    $stmt->bind_param('iid', $studentId, $gradeItemId, $marks);
    $stmt->execute();
    $stmt->close();
    $msg = "Grade saved.";
}

// ============================================================
// ACTION: INSTRUCTOR saves grading policies for a course (DECORATOR)
// ============================================================
if ($role === 'instructor' && isset($_POST['save_policies'])) {
    $courseId     = (int)$_POST['policy_course_id'];
    $applyCurve   = isset($_POST['pol_curve'])   ? 1 : 0;
    $curveAmount  = (float)($_POST['pol_curve_amount']   ?? 5.0);
    $dropLowest   = isset($_POST['pol_drop'])    ? 1 : 0;
    $applyBonus   = isset($_POST['pol_bonus'])   ? 1 : 0;
    $bonusAmount  = (float)($_POST['pol_bonus_amount']   ?? 2.0);
    $applyPenalty = isset($_POST['pol_penalty']) ? 1 : 0;
    $penaltyAmount = (float)($_POST['pol_penalty_amount'] ?? 5.0);
    $applyMax     = isset($_POST['pol_max'])     ? 1 : 0;
    $maxScore     = (float)($_POST['pol_max_score']      ?? 70.0);
    $applyLetter  = isset($_POST['pol_letter'])  ? 1 : 0;

    $stmt = $db->prepare(
        "INSERT INTO course_grading_policies
            (course_id, apply_curve, curve_amount, drop_lowest, apply_bonus, bonus_amount,
             apply_penalty, penalty_amount, apply_max, max_score, apply_letter)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            apply_curve = VALUES(apply_curve), curve_amount = VALUES(curve_amount),
            drop_lowest = VALUES(drop_lowest),
            apply_bonus = VALUES(apply_bonus), bonus_amount = VALUES(bonus_amount),
            apply_penalty = VALUES(apply_penalty), penalty_amount = VALUES(penalty_amount),
            apply_max = VALUES(apply_max), max_score = VALUES(max_score),
            apply_letter = VALUES(apply_letter)"
    );
    $stmt->bind_param('iiiiididiid',
        $courseId, $applyCurve, $curveAmount, $dropLowest,
        $applyBonus, $bonusAmount, $applyPenalty, $penaltyAmount,
        $applyMax, $maxScore, $applyLetter
    );
    $stmt->execute();
    $stmt->close();
    $msg = "Grading policies saved. Students will see updated grades immediately.";
}

// ============================================================
// ACTION: STUDENT enrolls in a course
// ============================================================
if ($role === 'student' && isset($_GET['enroll'])) {
    $courseId = (int)$_GET['enroll'];

    $prereqOk  = true;
    $prereqMsg = '';
    $stmt = $db->prepare(
        "SELECT p.required_course_id, c.title AS req_title
         FROM prerequisites p JOIN courses c ON p.required_course_id = c.courseid
         WHERE p.course_id = ?"
    );
    $stmt->bind_param('i', $courseId);
    $stmt->execute();
    $prereqs = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    $stmt->close();

    foreach ($prereqs as $prereq) {
        $reqId = $prereq['required_course_id'];
        $stmt2 = $db->prepare(
            "SELECT SUM(g.marks * gi.weight) / SUM(gi.weight) AS avg
             FROM grades g JOIN grade_items gi ON g.grade_item_id = gi.id
             WHERE g.student_id = ? AND gi.course_id = ?"
        );
        $stmt2->bind_param('ii', $uid, $reqId);
        $stmt2->execute();
        $avg = (float)($stmt2->get_result()->fetch_assoc()['avg'] ?? 0);
        $stmt2->close();

        if ($avg < 50) {
            $prereqOk  = false;
            $prereqMsg = "Cannot enroll: prerequisite '{$prereq['req_title']}' not passed yet (score: " . round($avg,1) . "%).";
        }
    }

    if ($prereqOk) {
        $stmt = $db->prepare(
            "INSERT IGNORE INTO enrollments (student_id, course_id) VALUES (?, ?)"
        );
        $stmt->bind_param('ii', $uid, $courseId);
        $stmt->execute();
        $stmt->close();
        $msg = "Enrolled successfully.";
    } else {
        $msg = $prereqMsg;
    }
}

// ============================================================
// ACTION: Mark notifications as read
// ============================================================
if (isset($_GET['mark_read'])) {
    $db->query("UPDATE notifications SET is_read = 1 WHERE student_id = $uid");
    header('Location: dashboard.php');
    exit;
}

// ============================================================
// DATA FETCHING
// ============================================================

$allCourses = $db->query(
    "SELECT c.*, u.name AS instructor_name
     FROM courses c JOIN users u ON c.instructor_id = u.userid
     ORDER BY c.courseid"
)->fetch_all(MYSQLI_ASSOC);

$instructors = $db->query(
    "SELECT userid, name FROM users WHERE role = 'instructor'"
)->fetch_all(MYSQLI_ASSOC);

$students = $db->query(
    "SELECT userid, name FROM users WHERE role = 'student'"
)->fetch_all(MYSQLI_ASSOC);

$enrolledIds = [];
if ($role === 'student') {
    $res = $db->query("SELECT course_id FROM enrollments WHERE student_id = $uid");
    while ($r = $res->fetch_assoc()) {
        $enrolledIds[] = (int)$r['course_id'];
    }
}

$instCourses = [];
if ($role === 'instructor') {
    $instCourses = $db->query(
        "SELECT * FROM courses WHERE instructor_id = $uid"
    )->fetch_all(MYSQLI_ASSOC);
}

$notifications = [];
$unreadCount   = 0;
if ($role === 'student') {
    $notifications = $db->query(
        "SELECT * FROM notifications WHERE student_id = $uid ORDER BY created_at DESC LIMIT 20"
    )->fetch_all(MYSQLI_ASSOC);
    foreach ($notifications as $n) {
        if (!$n['is_read']) $unreadCount++;
    }
}

$tab = $_GET['tab'] ?? 'main';

// ============================================================
// GRADE CALCULATION HELPER (Decorator pattern)
//
// Reads the grading policy for this course from course_grading_policies,
// builds the decorator stack, calculates the final grade, and logs
// the result to grade_history.
//
// Returns an array with:
//   components  -> the grade items used
//   final       -> the calculated float grade (or null if no grades yet)
//   policy      -> the active policy chain string for display
//   allItems    -> all grade items including ungraded ones
//   letter      -> letter grade string if LetterGradeDecorator is active
// ============================================================
function getStudentGrade(mysqli $db, int $studentId, int $courseId): array
{
    $items = $db->query(
        "SELECT gi.*, g.marks
         FROM grade_items gi
         LEFT JOIN grades g ON gi.id = g.grade_item_id AND g.student_id = $studentId
         WHERE gi.course_id = $courseId"
    )->fetch_all(MYSQLI_ASSOC);

    $components = [];
    foreach ($items as $item) {
        if ($item['marks'] !== null) {
            $components[] = [
                'name'   => $item['name'],
                'marks'  => (float)$item['marks'],
                'weight' => (float)$item['weight']
            ];
        }
    }

    if (empty($components)) {
        return ['components' => [], 'final' => null, 'policy' => 'No grades yet', 'allItems' => $items, 'letter' => null];
    }

    // Load the policy flags the instructor saved for this course
    $policyRow = $db->query(
        "SELECT * FROM course_grading_policies WHERE course_id = $courseId"
    )->fetch_assoc();

    // If no policy row exists yet, use all-off defaults
    $applyCurve    = (bool)($policyRow['apply_curve']   ?? false);
    $curveAmount   = (float)($policyRow['curve_amount']  ?? 5.0);
    $dropLowest    = (bool)($policyRow['drop_lowest']   ?? false);
    $applyBonus    = (bool)($policyRow['apply_bonus']   ?? false);
    $bonusAmount   = (float)($policyRow['bonus_amount']  ?? 2.0);
    $applyPenalty  = (bool)($policyRow['apply_penalty'] ?? false);
    $penaltyAmount = (float)($policyRow['penalty_amount'] ?? 5.0);
    $applyMax      = (bool)($policyRow['apply_max']     ?? false);
    $maxScore      = (float)($policyRow['max_score']    ?? 70.0);
    $applyLetter   = (bool)($policyRow['apply_letter']  ?? false);

    $calc  = buildGradeCalculator(
        $applyCurve, $dropLowest, $applyBonus,
        $applyPenalty, $applyMax, $applyLetter,
        $curveAmount, $bonusAmount, $penaltyAmount, $maxScore
    );
    $final = $calc->calculate($components);

    // Get letter grade if LetterGradeDecorator is active
    $letter = null;
    if ($applyLetter && $calc instanceof LetterGradeDecorator) {
        $letter = $calc->getLetter($components);
    }

    // Log this calculation to grade_history
    // This lets instructors see a record of every time grades were calculated
    $chain = $calc->describe();
    $stmt = $db->prepare(
        "INSERT INTO grade_history (student_id, course_id, policy_chain, final_grade, letter_grade)
         VALUES (?, ?, ?, ?, ?)"
    );
    $stmt->bind_param('iisds', $studentId, $courseId, $chain, $final, $letter);
    $stmt->execute();
    $stmt->close();

    return [
        'components' => $components,
        'final'      => $final,
        'policy'     => $chain,
        'allItems'   => $items,
        'letter'     => $letter
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>CMS Dashboard</title>
<style>
  * { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    font-family: Arial, sans-serif;
    background: #f0f2f5;
    color: #222;
    font-size: 0.9rem;
  }

  .topnav {
    background: #1e2d4a;
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0 24px;
    height: 52px;
  }
  .topnav .brand { font-size: 1rem; font-weight: bold; }
  .topnav .userinfo { font-size: 0.82rem; color: #aac; }
  .topnav a.logout {
    color: #aac;
    text-decoration: none;
    margin-left: 16px;
    font-size: 0.82rem;
  }
  .topnav a.logout:hover { color: #fff; }

  .tabs {
    background: #fff;
    border-bottom: 2px solid #dde;
    display: flex;
    padding: 0 24px;
    gap: 4px;
  }
  .tab-btn {
    padding: 12px 18px;
    background: none;
    border: none;
    border-bottom: 2px solid transparent;
    margin-bottom: -2px;
    cursor: pointer;
    font-size: 0.85rem;
    color: #555;
  }
  .tab-btn:hover { color: #1e2d4a; }
  .tab-btn.active { color: #1e2d4a; border-bottom-color: #1e2d4a; font-weight: bold; }

  .content { padding: 24px; max-width: 1100px; margin: 0 auto; }
  .tab-panel { display: none; }
  .tab-panel.active { display: block; }

  .card {
    background: #fff;
    border: 1px solid #dde;
    border-radius: 5px;
    padding: 20px;
    margin-bottom: 18px;
  }
  .card h3 {
    font-size: 0.95rem;
    font-weight: bold;
    color: #1e2d4a;
    margin-bottom: 14px;
    padding-bottom: 8px;
    border-bottom: 1px solid #eee;
  }
  .pattern-note {
    display: inline-block;
    background: #e8f0fe;
    border: 1px solid #c5d5f8;
    color: #3a5bc7;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 0.72rem;
    margin-left: 8px;
    font-weight: normal;
    vertical-align: middle;
  }

  table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
  th { text-align: left; padding: 8px 10px; background: #f5f6fa; border-bottom: 2px solid #dde; font-size: 0.78rem; color: #555; }
  td { padding: 9px 10px; border-bottom: 1px solid #eef; }
  tr:last-child td { border-bottom: none; }
  tr:hover td { background: #fafbff; }

  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
  .form-group { margin-bottom: 12px; }
  .form-group label { display: block; font-size: 0.78rem; font-weight: bold; color: #444; margin-bottom: 4px; }
  .form-group input,
  .form-group select,
  .form-group textarea {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #bbb;
    border-radius: 4px;
    font-size: 0.87rem;
    font-family: Arial, sans-serif;
  }
  .form-group input:focus,
  .form-group select:focus,
  .form-group textarea:focus { outline: none; border-color: #3a6bc8; }
  .form-group textarea { resize: vertical; min-height: 70px; }

  .btn {
    padding: 8px 18px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 0.85rem;
  }
  .btn-primary { background: #1e2d4a; color: #fff; }
  .btn-primary:hover { background: #263d62; }
  .btn-success { background: #2e7d32; color: #fff; }
  .btn-success:hover { background: #246028; }
  .btn-sm { padding: 4px 12px; font-size: 0.78rem; }
  .btn-link {
    background: none;
    border: none;
    color: #3a6bc8;
    cursor: pointer;
    font-size: 0.85rem;
    text-decoration: underline;
    padding: 0;
  }

  .badge {
    display: inline-block;
    padding: 2px 8px;
    border-radius: 3px;
    font-size: 0.72rem;
    font-weight: bold;
  }
  .badge-green  { background: #d4edda; color: #155724; }
  .badge-red    { background: #f8d7da; color: #721c24; }
  .badge-blue   { background: #cce5ff; color: #004085; }
  .badge-grey   { background: #e9ecef; color: #495057; }
  .badge-orange { background: #fff3cd; color: #856404; }

  .msg-success {
    background: #d4edda; border: 1px solid #c3e6cb; color: #155724;
    padding: 10px 14px; border-radius: 4px; margin-bottom: 16px; font-size: 0.87rem;
  }

  .grade-bar-wrap { margin: 6px 0; }
  .grade-bar { background: #e9ecef; height: 8px; border-radius: 4px; overflow: hidden; }
  .grade-fill { height: 100%; background: #1e2d4a; border-radius: 4px; }

  .tree-section {
    border: 1px solid #dde;
    border-radius: 4px;
    margin-bottom: 10px;
    overflow: hidden;
  }
  .tree-section-header {
    background: #f5f6fa;
    padding: 9px 14px;
    font-weight: bold;
    font-size: 0.87rem;
    display: flex;
    justify-content: space-between;
    align-items: center;
  }
  .tree-item {
    display: flex;
    align-items: center;
    padding: 7px 14px 7px 28px;
    border-top: 1px solid #f0f0f5;
    font-size: 0.84rem;
    gap: 10px;
  }
  .tree-item:hover { background: #fafbff; }
  .item-type-tag {
    font-size: 0.7rem;
    padding: 2px 7px;
    border-radius: 3px;
    margin-left: auto;
  }
  .type-lecture    { background: #e8f5e9; color: #2e7d32; }
  .type-assignment { background: #fff3e0; color: #e65100; }
  .type-quiz       { background: #e3f2fd; color: #1565c0; }

  .notif-item {
    padding: 10px 0;
    border-bottom: 1px solid #eef;
    display: flex;
    gap: 12px;
  }
  .notif-item:last-child { border-bottom: none; }
  .notif-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: #1e2d4a; margin-top: 5px; flex-shrink: 0;
  }
  .notif-dot.read { background: #ccc; }
  .notif-time { font-size: 0.75rem; color: #888; }

  .access-status { font-size: 0.78rem; color: #888; }
  .access-ok   { color: #2e7d32; }
  .access-deny { color: #b71c1c; }

  .empty { color: #888; font-size: 0.85rem; text-align: center; padding: 18px; }
  .section-desc { color: #666; font-size: 0.82rem; margin-bottom: 16px; line-height: 1.5; }

  .policy-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px 24px;
    margin-bottom: 14px;
  }
  .policy-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid #f0f0f0;
  }
  .policy-row label { font-size: 0.85rem; flex: 1; }
  .policy-row input[type=number] {
    width: 70px;
    padding: 4px 6px;
    border: 1px solid #bbb;
    border-radius: 3px;
    font-size: 0.82rem;
  }
</style>
</head>
<body>

<div class="topnav">
  <span class="brand">Course Management System</span>
  <span>
    <span class="userinfo">
      <?= htmlspecialchars($userObj->getName()) ?>
      &mdash; <?= htmlspecialchars($userObj->getLabel()) ?>
    </span>
    <a href="logout.php" class="logout">Logout</a>
  </span>
</div>

<div class="tabs">
  <?php if ($role === 'admin'): ?>
    <button class="tab-btn <?= $tab==='main'?'active':'' ?>"    onclick="setTab('main')">Dashboard</button>
    <button class="tab-btn <?= $tab==='create'?'active':'' ?>"  onclick="setTab('create')">Create Course</button>
    <button class="tab-btn <?= $tab==='prereq'?'active':'' ?>"  onclick="setTab('prereq')">Prerequisites</button>
    <button class="tab-btn <?= $tab==='users'?'active':'' ?>"   onclick="setTab('users')">Users</button>

  <?php elseif ($role === 'instructor'): ?>
    <button class="tab-btn <?= $tab==='main'?'active':'' ?>"       onclick="setTab('main')">My Courses</button>
    <button class="tab-btn <?= $tab==='announce'?'active':'' ?>"   onclick="setTab('announce')">Announcements</button>
    <button class="tab-btn <?= $tab==='attendance'?'active':'' ?>" onclick="setTab('attendance')">Attendance</button>
    <button class="tab-btn <?= $tab==='grading'?'active':'' ?>"    onclick="setTab('grading')">Grading</button>

  <?php else: ?>
    <button class="tab-btn <?= $tab==='main'?'active':'' ?>"       onclick="setTab('main')">Browse Courses</button>
    <button class="tab-btn <?= $tab==='mycourses'?'active':'' ?>"  onclick="setTab('mycourses')">My Courses</button>
    <button class="tab-btn <?= $tab==='grades'?'active':'' ?>"     onclick="setTab('grades')">My Grades</button>
    <button class="tab-btn <?= $tab==='notifs'?'active':'' ?>">
      Notifications<?php if ($unreadCount > 0): ?> (<?= $unreadCount ?>)<?php endif; ?>
    </button>
  <?php endif; ?>
</div>

<div class="content">

  <?php if ($msg): ?>
  <div class="msg-success"><?= htmlspecialchars($msg) ?></div>
  <?php endif; ?>

  <!-- ===================================================
       ADMIN PANELS
  ==================================================== -->
  <?php if ($role === 'admin'): ?>

  <div class="tab-panel <?= $tab==='main'?'active':'' ?>" id="panel-main">
    <div class="card">
      <h3>All Courses</h3>
      <?php if (empty($allCourses)): ?>
        <p class="empty">No courses yet. Use "Create Course" to add one.</p>
      <?php else: ?>
      <table>
        <thead>
          <tr><th>Title</th><th>Instructor</th><th>Enrolled</th><th>Prerequisite</th></tr>
        </thead>
        <tbody>
          <?php foreach ($allCourses as $c):
            $enrolled = (int)$db->query(
                "SELECT COUNT(*) AS n FROM enrollments WHERE course_id = {$c['courseid']}"
            )->fetch_assoc()['n'];
            $prereqRow = $db->query(
                "SELECT c2.title FROM prerequisites p
                 JOIN courses c2 ON p.required_course_id = c2.courseid
                 WHERE p.course_id = {$c['courseid']}"
            )->fetch_assoc();
          ?>
          <tr>
            <td><?= htmlspecialchars($c['title']) ?></td>
            <td><?= htmlspecialchars($c['instructor_name']) ?></td>
            <td><?= $enrolled ?> student(s)</td>
            <td><?= $prereqRow ? htmlspecialchars($prereqRow['title']) : '<span class="badge badge-grey">None</span>' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="tab-panel <?= $tab==='create'?'active':'' ?>" id="panel-create">
    <div class="card">
      <h3>Create Course <span class="pattern-note">Builder Pattern</span></h3>
      <p class="section-desc">
        Uses the <strong>Builder Pattern</strong>. The CourseDirector decides the structure
        based on the course type. StandardCourseBuilder produces a full-semester course.
        ShortCourseBuilder produces a 1-section workshop course.
      </p>
      <form method="POST">
        <div class="form-row">
          <div class="form-group">
            <label>Course Title</label>
            <input type="text" name="title" placeholder="e.g. Data Structures" required>
          </div>
          <div class="form-group">
            <label>Assign Instructor</label>
            <select name="instructor_id" required>
              <option value="">-- select instructor --</option>
              <?php foreach ($instructors as $i): ?>
              <option value="<?= $i['userid'] ?>"><?= htmlspecialchars($i['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Description</label>
          <textarea name="description" placeholder="Brief course description"></textarea>
        </div>
        <div class="form-group">
          <label>Course Type</label>
          <select name="course_type">
            <option value="standard">Standard Course (3 sections, Midterm + Assignment + Final)</option>
            <option value="short">Short Course (1 section, Final Project only)</option>
          </select>
        </div>
        <button type="submit" name="create_course" class="btn btn-primary">Create Course</button>
      </form>
    </div>
  </div>

  <div class="tab-panel <?= $tab==='prereq'?'active':'' ?>" id="panel-prereq">
    <div class="card">
      <h3>Set Prerequisites <span class="pattern-note">Used by Proxy Pattern</span></h3>
      <p class="section-desc">
        Prerequisites are checked by <strong>CourseProxy</strong> (Check 2 of 3).
        A student cannot access a course until they have passed its prerequisite with >= 50%.
      </p>
      <form method="POST">
        <div class="form-row">
          <div class="form-group">
            <label>Course (requires a prerequisite)</label>
            <select name="prereq_course_id">
              <?php foreach ($allCourses as $c): ?>
              <option value="<?= $c['courseid'] ?>"><?= htmlspecialchars($c['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Required Course (must pass this first)</label>
            <select name="required_course_id">
              <?php foreach ($allCourses as $c): ?>
              <option value="<?= $c['courseid'] ?>"><?= htmlspecialchars($c['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <button type="submit" name="set_prereq" class="btn btn-primary">Save Prerequisite</button>
      </form>
    </div>
    <div class="card">
      <h3>Current Prerequisites</h3>
      <table>
        <thead><tr><th>Course</th><th>Requires</th></tr></thead>
        <tbody>
          <?php
          $prereqList = $db->query(
              "SELECT c1.title AS course, c2.title AS requires
               FROM prerequisites p
               JOIN courses c1 ON p.course_id = c1.courseid
               JOIN courses c2 ON p.required_course_id = c2.courseid"
          )->fetch_all(MYSQLI_ASSOC);
          foreach ($prereqList as $p): ?>
          <tr>
            <td><?= htmlspecialchars($p['course']) ?></td>
            <td><?= htmlspecialchars($p['requires']) ?></td>
          </tr>
          <?php endforeach;
          if (empty($prereqList)): ?>
          <tr><td colspan="2" class="empty">No prerequisites set.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="tab-panel <?= $tab==='users'?'active':'' ?>" id="panel-users">
    <div class="card">
      <h3>Add Instructor</h3>
      <form method="POST">
        <div class="form-row">
          <div class="form-group">
            <label>Name</label>
            <input type="text" name="inst_name" placeholder="Full name" required>
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="inst_email" placeholder="email@example.com" required>
          </div>
          <div class="form-group">
            <label>Password</label>
            <input type="password" name="inst_password" placeholder="Temporary password" required>
          </div>
        </div>
        <button type="submit" name="add_instructor" class="btn btn-primary">Add Instructor</button>
      </form>
    </div>
    <div class="card">
      <h3>Add Student</h3>
      <form method="POST">
        <div class="form-row">
          <div class="form-group">
            <label>Name</label>
            <input type="text" name="stud_name" placeholder="Full name" required>
          </div>
          <div class="form-group">
            <label>Email</label>
            <input type="email" name="stud_email" placeholder="email@example.com" required>
          </div>
          <div class="form-group">
            <label>Password</label>
            <input type="password" name="stud_password" placeholder="Temporary password" required>
          </div>
        </div>
        <button type="submit" name="add_student" class="btn btn-primary">Add Student</button>
      </form>
    </div>
    <div class="card">
      <h3>All Users <span class="pattern-note">Factory Pattern creates User objects</span></h3>
      <p class="section-desc">
        When any user logs in, <strong>UserFactory::create()</strong> reads their role and
        returns the appropriate subclass (Student, Instructor, or Admin).
      </p>
      <table>
        <thead><tr><th>Name</th><th>Email</th><th>Role</th></tr></thead>
        <tbody>
          <?php
          $allUsers = $db->query("SELECT * FROM users")->fetch_all(MYSQLI_ASSOC);
          foreach ($allUsers as $u):
            $badgeClass = match($u['role']) {
                'admin'      => 'badge-red',
                'instructor' => 'badge-orange',
                default      => 'badge-blue'
            };
          ?>
          <tr>
            <td><?= htmlspecialchars($u['name']) ?></td>
            <td><?= htmlspecialchars($u['email']) ?></td>
            <td><span class="badge <?= $badgeClass ?>"><?= $u['role'] ?></span></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>


  <!-- ===================================================
       INSTRUCTOR PANELS
  ==================================================== -->
  <?php elseif ($role === 'instructor'): ?>

  <div class="tab-panel <?= $tab==='main'?'active':'' ?>" id="panel-main">
    <?php if (empty($instCourses)): ?>
      <div class="card"><p class="empty">No courses assigned to you yet.</p></div>
    <?php endif; ?>
    <?php foreach ($instCourses as $c):
      $loader   = new CourseStructureLoader($db);
      $sections = $loader->load((int)$c['courseid']);
    ?>
    <div class="card">
      <h3>
        <?= htmlspecialchars($c['title']) ?>
        <span class="pattern-note">Composite Pattern</span>
      </h3>
      <p class="section-desc">
        Course structure uses <strong>Composite Pattern</strong>.
        CourseSection is the composite node; CourseItem is the leaf.
        Calling render() on a section calls render() on all its children automatically.
      </p>
      <?php if (empty($sections)): ?>
        <p class="empty">No sections in this course.</p>
      <?php else: ?>
        <?php foreach ($sections as $section): ?>
        <div class="tree-section">
          <div class="tree-section-header">
            <?= htmlspecialchars($section->getTitle()) ?>
            <span class="badge badge-grey"><?= $section->getChildCount() ?> item(s)</span>
          </div>
          <?php foreach ($section->getChildren() as $item): ?>
          <div class="tree-item">
            <?= htmlspecialchars($item->getTitle()) ?>
            <span class="item-type-tag type-<?= $item->getType() ?>"><?= $item->getType() ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="tab-panel <?= $tab==='announce'?'active':'' ?>" id="panel-announce">
    <div class="card">
      <h3>Post Announcement <span class="pattern-note">Observer Pattern - Trigger 1</span></h3>
      <p class="section-desc">
        Posting an announcement triggers the <strong>Observer Pattern</strong>.
        CourseNotifier is the Subject. One DatabaseNotifier Observer is registered per enrolled student.
        When notifyObservers() fires, every student's update() is called automatically.
      </p>
      <form method="POST">
        <div class="form-group">
          <label>Course</label>
          <select name="course_id">
            <?php foreach ($instCourses as $c): ?>
            <option value="<?= $c['courseid'] ?>"><?= htmlspecialchars($c['title']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Message</label>
          <textarea name="message" placeholder="Type your announcement..." required></textarea>
        </div>
        <button type="submit" name="post_announcement" class="btn btn-primary">Post and Notify Students</button>
      </form>
    </div>
    <div class="card">
      <h3>Recent Announcements</h3>
      <?php
      $ann = $db->query(
          "SELECT a.*, c.title AS course_title
           FROM announcements a JOIN courses c ON a.course_id = c.courseid
           WHERE a.instructor_id = $uid ORDER BY a.created_at DESC LIMIT 10"
      )->fetch_all(MYSQLI_ASSOC);
      if (empty($ann)): ?>
        <p class="empty">No announcements posted yet.</p>
      <?php else: ?>
      <table>
        <thead><tr><th>Course</th><th>Message</th><th>Posted</th></tr></thead>
        <tbody>
          <?php foreach ($ann as $a): ?>
          <tr>
            <td><?= htmlspecialchars($a['course_title']) ?></td>
            <td><?= htmlspecialchars($a['message']) ?></td>
            <td><?= $a['created_at'] ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <div class="tab-panel <?= $tab==='attendance'?'active':'' ?>" id="panel-attendance">
    <div class="card">
      <h3>Mark Attendance <span class="pattern-note">Observer Pattern - Trigger 2</span></h3>
      <p class="section-desc">
        Marking a student <strong>absent</strong> triggers the Observer Pattern.
        An AttendanceNotifier fires for that student. The Proxy also uses this data
        (Check 3 of 3) to block students below 75% attendance.
      </p>
      <form method="POST">
        <div class="form-row">
          <div class="form-group">
            <label>Course</label>
            <select name="att_course_id">
              <?php foreach ($instCourses as $c): ?>
              <option value="<?= $c['courseid'] ?>"><?= htmlspecialchars($c['title']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Student</label>
            <select name="att_student_id">
              <?php foreach ($students as $s): ?>
              <option value="<?= $s['userid'] ?>"><?= htmlspecialchars($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Date</label>
            <input type="date" name="att_date" value="<?= date('Y-m-d') ?>" required>
          </div>
          <div class="form-group">
            <label>Status</label>
            <select name="att_status">
              <option value="present">Present</option>
              <option value="absent">Absent (triggers Observer notification)</option>
            </select>
          </div>
        </div>
        <button type="submit" name="mark_attendance" class="btn btn-primary">Mark Attendance</button>
      </form>
    </div>
    <div class="card">
      <h3>Attendance Summary</h3>
      <table>
        <thead><tr><th>Student</th><th>Course</th><th>Present</th><th>Total</th><th>Percentage</th><th>Proxy Result</th></tr></thead>
        <tbody>
          <?php
          $attData = $db->query(
              "SELECT u.name, a.course_id, a.student_id,
                      SUM(CASE WHEN a.status='present' THEN 1 ELSE 0 END) AS present,
                      COUNT(*) AS total,
                      c.title AS course_title
               FROM attendance a
               JOIN users u ON a.student_id = u.userid
               JOIN courses c ON a.course_id = c.courseid
               WHERE c.instructor_id = $uid
               GROUP BY a.student_id, a.course_id"
          )->fetch_all(MYSQLI_ASSOC);
          foreach ($attData as $a):
            $pct     = $a['total'] > 0 ? round(($a['present']/$a['total'])*100, 1) : 0;
            $allowed = $pct >= 75;
          ?>
          <tr>
            <td><?= htmlspecialchars($a['name']) ?></td>
            <td><?= htmlspecialchars($a['course_title']) ?></td>
            <td><?= $a['present'] ?></td>
            <td><?= $a['total'] ?></td>
            <td><?= $pct ?>%</td>
            <td>
              <?php if ($allowed): ?>
                <span class="badge badge-green">Access OK</span>
              <?php else: ?>
                <span class="badge badge-red">Proxy Blocks</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach;
          if (empty($attData)): ?>
          <tr><td colspan="6" class="empty">No attendance records yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- Instructor: Grading tab
       Two sections:
       1. Enter raw marks per student/item (unchanged)
       2. Set grading policies per course (Decorator pattern)
          The instructor enables decorators here; students see the result.
       3. Grade history log - shows every calculation that was run -->
  <div class="tab-panel <?= $tab==='grading'?'active':'' ?>" id="panel-grading">

    <!-- Enter raw marks -->
    <div class="card">
      <h3>Enter Grades <span class="pattern-note">Decorator Pattern</span></h3>
      <form method="POST">
        <div class="form-row">
          <div class="form-group">
            <label>Student</label>
            <select name="grade_student_id">
              <?php foreach ($students as $s): ?>
              <option value="<?= $s['userid'] ?>"><?= htmlspecialchars($s['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>Grade Item</label>
            <select name="grade_item_id">
              <?php
              $gItems = $db->query(
                  "SELECT gi.*, c.title AS course_title FROM grade_items gi
                   JOIN courses c ON gi.course_id = c.courseid
                   WHERE c.instructor_id = $uid"
              )->fetch_all(MYSQLI_ASSOC);
              foreach ($gItems as $gi): ?>
              <option value="<?= $gi['id'] ?>">
                <?= htmlspecialchars($gi['course_title']) ?> - <?= htmlspecialchars($gi['name']) ?>
                (weight: <?= ($gi['weight']*100) ?>%)
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
        <div class="form-group">
          <label>Marks (0 - 100)</label>
          <input type="number" name="marks" min="0" max="100" step="0.5" required>
        </div>
        <button type="submit" name="enter_grade" class="btn btn-primary">Save Grade</button>
      </form>
    </div>

    <!-- Grading policies per course (Decorator controls) -->
    <div class="card">
      <h3>Grading Policies <span class="pattern-note">Decorator Pattern - instructor controls</span></h3>
      <p class="section-desc">
        Each checkbox below wraps a Decorator around the base WeightedGrade calculator
        for the selected course. Students see the result automatically — they do not
        control these settings. The active policy chain is shown on their Grades page.
      </p>
      <?php foreach ($instCourses as $c):
        $pol = $db->query(
            "SELECT * FROM course_grading_policies WHERE course_id = {$c['courseid']}"
        )->fetch_assoc();
        if (!$pol) $pol = [];
      ?>
      <form method="POST" style="margin-bottom: 24px; padding-bottom: 20px; border-bottom: 1px solid #eee;">
        <input type="hidden" name="policy_course_id" value="<?= $c['courseid'] ?>">
        <strong style="font-size:0.9rem;"><?= htmlspecialchars($c['title']) ?></strong>
        <div class="policy-grid" style="margin-top: 12px;">

          <div class="policy-row">
            <input type="checkbox" name="pol_curve" id="curve_<?= $c['courseid'] ?>"
              <?= !empty($pol['apply_curve']) ? 'checked' : '' ?>>
            <label for="curve_<?= $c['courseid'] ?>">Apply Curve — CurveDecorator (+N marks)</label>
            <input type="number" name="pol_curve_amount" value="<?= $pol['curve_amount'] ?? 5 ?>" min="0" max="20" step="0.5">
          </div>

          <div class="policy-row">
            <input type="checkbox" name="pol_drop" id="drop_<?= $c['courseid'] ?>"
              <?= !empty($pol['drop_lowest']) ? 'checked' : '' ?>>
            <label for="drop_<?= $c['courseid'] ?>">Drop Lowest Score — DropLowestDecorator</label>
          </div>

          <div class="policy-row">
            <input type="checkbox" name="pol_bonus" id="bonus_<?= $c['courseid'] ?>"
              <?= !empty($pol['apply_bonus']) ? 'checked' : '' ?>>
            <label for="bonus_<?= $c['courseid'] ?>">Bonus Marks — BonusDecorator (+N marks)</label>
            <input type="number" name="pol_bonus_amount" value="<?= $pol['bonus_amount'] ?? 2 ?>" min="0" max="20" step="0.5">
          </div>

          <div class="policy-row">
            <input type="checkbox" name="pol_penalty" id="penalty_<?= $c['courseid'] ?>"
              <?= !empty($pol['apply_penalty']) ? 'checked' : '' ?>>
            <label for="penalty_<?= $c['courseid'] ?>">Late Penalty — PenaltyDecorator (-N marks)</label>
            <input type="number" name="pol_penalty_amount" value="<?= $pol['penalty_amount'] ?? 5 ?>" min="0" max="50" step="0.5">
          </div>

          <div class="policy-row">
            <input type="checkbox" name="pol_max" id="max_<?= $c['courseid'] ?>"
              <?= !empty($pol['apply_max']) ? 'checked' : '' ?>>
            <label for="max_<?= $c['courseid'] ?>">Max Score Cap — MaxScoreDecorator (ceiling)</label>
            <input type="number" name="pol_max_score" value="<?= $pol['max_score'] ?? 70 ?>" min="0" max="100" step="0.5">
          </div>

          <div class="policy-row">
            <input type="checkbox" name="pol_letter" id="letter_<?= $c['courseid'] ?>"
              <?= !empty($pol['apply_letter']) ? 'checked' : '' ?>>
            <label for="letter_<?= $c['courseid'] ?>">Show Letter Grade — LetterGradeDecorator (A/B/C/D/F)</label>
          </div>

        </div>
        <button type="submit" name="save_policies" class="btn btn-primary">Save Policies for this Course</button>
      </form>
      <?php endforeach; ?>
    </div>

    <!-- All grades recorded -->
    <div class="card">
      <h3>All Grades Recorded</h3>
      <table>
        <thead><tr><th>Student</th><th>Course</th><th>Item</th><th>Marks</th><th>Weight</th></tr></thead>
        <tbody>
          <?php
          $allGrades = $db->query(
              "SELECT u.name AS student, c.title AS course,
                      gi.name AS item, g.marks, gi.weight
               FROM grades g
               JOIN users u ON g.student_id = u.userid
               JOIN grade_items gi ON g.grade_item_id = gi.id
               JOIN courses c ON gi.course_id = c.courseid
               WHERE c.instructor_id = $uid"
          )->fetch_all(MYSQLI_ASSOC);
          foreach ($allGrades as $g): ?>
          <tr>
            <td><?= htmlspecialchars($g['student']) ?></td>
            <td><?= htmlspecialchars($g['course']) ?></td>
            <td><?= htmlspecialchars($g['item']) ?></td>
            <td><?= $g['marks'] ?>/100</td>
            <td><?= ($g['weight']*100) ?>%</td>
          </tr>
          <?php endforeach;
          if (empty($allGrades)): ?>
          <tr><td colspan="5" class="empty">No grades entered yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <!-- Grade history log -->
    <div class="card">
      <h3>Grade History Log <span class="pattern-note">Audit trail - saved every time grades are calculated</span></h3>
      <p class="section-desc">
        Every time a student visits their Grades page, <code>getStudentGrade()</code> runs
        and logs a row here. This shows which policies were active, what the final grade
        was, and when the calculation happened.
      </p>
      <table>
        <thead>
          <tr><th>Student</th><th>Course</th><th>Policy Chain</th><th>Grade</th><th>Letter</th><th>When</th></tr>
        </thead>
        <tbody>
          <?php
          $history = $db->query(
              "SELECT gh.*, u.name AS student_name, c.title AS course_title
               FROM grade_history gh
               JOIN users u ON gh.student_id = u.userid
               JOIN courses c ON gh.course_id = c.courseid
               WHERE c.instructor_id = $uid
               ORDER BY gh.calculated_at DESC LIMIT 30"
          )->fetch_all(MYSQLI_ASSOC);
          foreach ($history as $h): ?>
          <tr>
            <td><?= htmlspecialchars($h['student_name']) ?></td>
            <td><?= htmlspecialchars($h['course_title']) ?></td>
            <td style="font-size:0.78rem; color:#555;"><?= htmlspecialchars($h['policy_chain']) ?></td>
            <td><strong><?= $h['final_grade'] ?></strong>/100</td>
            <td><?= $h['letter_grade'] ?? '-' ?></td>
            <td style="font-size:0.78rem; color:#888;"><?= $h['calculated_at'] ?></td>
          </tr>
          <?php endforeach;
          if (empty($history)): ?>
          <tr><td colspan="6" class="empty">No history yet. Students need to visit their Grades page.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>


  <!-- ===================================================
       STUDENT PANELS
  ==================================================== -->
  <?php else: ?>

  <div class="tab-panel <?= $tab==='main'?'active':'' ?>" id="panel-main">
    <div class="card">
      <h3>Available Courses <span class="pattern-note">Proxy Pattern - Access Control</span></h3>
      <p class="section-desc">
        Every row calls <strong>CourseProxy::checkAccess()</strong>.
        The Proxy runs 3 checks: enrolled?, prerequisite passed?, attendance >= 75%?
        Login as <strong>Bob</strong> to see the attendance block.
        Login as <strong>Alice</strong> and try course 2 for the prerequisite block.
      </p>
      <table>
        <thead>
          <tr><th>Course</th><th>Instructor</th><th>Enrollment</th><th>Proxy Check</th><th>Action</th></tr>
        </thead>
        <tbody>
          <?php
          $proxy = new CourseProxy($role);
          foreach ($allCourses as $c):
            $cId    = (int)$c['courseid'];
            $access = $proxy->checkAccess($uid, $cId);
            $isEnrolled = in_array($cId, $enrolledIds);
            $attPct = $proxy->getAttendancePct($uid, $cId);
          ?>
          <tr>
            <td><?= htmlspecialchars($c['title']) ?></td>
            <td><?= htmlspecialchars($c['instructor_name']) ?></td>
            <td>
              <?php if ($isEnrolled): ?>
                <span class="badge badge-green">Enrolled</span>
                <?php if ($attPct !== null): ?>
                  <br><small>Attendance: <?= $attPct ?>%</small>
                <?php endif; ?>
              <?php else: ?>
                <span class="badge badge-grey">Not enrolled</span>
              <?php endif; ?>
            </td>
            <td class="access-status <?= $access['allowed'] ? 'access-ok' : 'access-deny' ?>">
              <?= htmlspecialchars($access['reason']) ?>
            </td>
            <td>
              <?php if (!$isEnrolled): ?>
                <a href="?enroll=<?= $cId ?>"><button class="btn btn-success btn-sm">Enroll</button></a>
              <?php elseif ($access['allowed']): ?>
                <button class="btn btn-sm btn-primary" onclick="setTab('mycourses')">View Content</button>
              <?php else: ?>
                <span style="color:#b71c1c; font-size:0.78rem;">Blocked</span>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="tab-panel <?= $tab==='mycourses'?'active':'' ?>" id="panel-mycourses">
    <?php
    $proxy = new CourseProxy($role);
    $enrolledCourses = $db->query(
        "SELECT c.*, u.name AS instructor_name
         FROM courses c
         JOIN enrollments e ON c.courseid = e.course_id
         JOIN users u ON c.instructor_id = u.userid
         WHERE e.student_id = $uid"
    )->fetch_all(MYSQLI_ASSOC);

    if (empty($enrolledCourses)): ?>
      <div class="card"><p class="empty">You are not enrolled in any courses yet.</p></div>
    <?php endif; ?>

    <?php foreach ($enrolledCourses as $c):
      $cId    = (int)$c['courseid'];
      $access = $proxy->checkAccess($uid, $cId);
    ?>
    <div class="card">
      <h3>
        <?= htmlspecialchars($c['title']) ?>
        <span class="pattern-note">Composite Pattern</span>
      </h3>
      <p style="color:#666; font-size:0.82rem; margin-bottom:12px;">
        Instructor: <?= htmlspecialchars($c['instructor_name']) ?>
      </p>

      <?php if (!$access['allowed']): ?>
        <div style="background:#fdecea; border:1px solid #f5c6cb; padding:10px 14px; border-radius:4px; color:#721c24; font-size:0.85rem;">
          <strong>Proxy blocked access:</strong> <?= htmlspecialchars($access['reason']) ?>
        </div>
      <?php else: ?>
        <?php
        $loader   = new CourseStructureLoader($db);
        $sections = $loader->load($cId);
        foreach ($sections as $section): ?>
        <div class="tree-section">
          <div class="tree-section-header">
            <?= htmlspecialchars($section->getTitle()) ?>
            <span class="badge badge-grey"><?= $section->getChildCount() ?> item(s)</span>
          </div>
          <?php foreach ($section->getChildren() as $item): ?>
          <div class="tree-item">
            <?= htmlspecialchars($item->getTitle()) ?>
            <span class="item-type-tag type-<?= $item->getType() ?>"><?= $item->getType() ?></span>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endforeach; ?>

        <?php
        $annList = $db->query(
            "SELECT * FROM announcements WHERE course_id=$cId ORDER BY created_at DESC LIMIT 3"
        )->fetch_all(MYSQLI_ASSOC);
        if (!empty($annList)): ?>
        <div style="margin-top:14px; border-top:1px solid #eee; padding-top:12px;">
          <strong style="font-size:0.85rem;">Recent Announcements</strong>
          <?php foreach ($annList as $a): ?>
          <div class="notif-item" style="margin-top:8px;">
            <div class="notif-dot"></div>
            <div>
              <div style="font-size:0.85rem;"><?= htmlspecialchars($a['message']) ?></div>
              <div class="notif-time"><?= $a['created_at'] ?></div>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <!-- Student: Grades
       Policies are now set by the instructor, not the student.
       This page just reads from course_grading_policies and shows
       whatever decorators the instructor has enabled. -->
  <div class="tab-panel <?= $tab==='grades'?'active':'' ?>" id="panel-grades">
    <div class="card">
      <h3>Grade Calculator <span class="pattern-note">Decorator Pattern</span></h3>
      <p class="section-desc">
        Your grades are calculated using the <strong>Decorator Pattern</strong>.
        The base calculator is <strong>WeightedGrade</strong>. Your instructor may have
        enabled additional policies (curve, bonus, penalty, etc.) which stack on top as Decorators.
        The active policy chain is shown below each course.
      </p>
    </div>

    <?php
    $enrolledCourses2 = $db->query(
        "SELECT c.* FROM courses c
         JOIN enrollments e ON c.courseid = e.course_id
         WHERE e.student_id = $uid"
    )->fetch_all(MYSQLI_ASSOC);

    foreach ($enrolledCourses2 as $c):
      $result = getStudentGrade($db, $uid, (int)$c['courseid']);
    ?>
    <div class="card">
      <h3><?= htmlspecialchars($c['title']) ?></h3>

      <?php if ($result['final'] === null): ?>
        <p class="empty">No grades recorded yet.</p>
      <?php else: ?>
        <table>
          <thead>
            <tr><th>Item</th><th>Marks</th><th>Weight</th><th>Contribution</th></tr>
          </thead>
          <tbody>
            <?php foreach ($result['components'] as $comp): ?>
            <tr>
              <td><?= htmlspecialchars($comp['name']) ?></td>
              <td><?= $comp['marks'] ?> / 100</td>
              <td><?= round($comp['weight']*100) ?>%</td>
              <td><?= round($comp['marks'] * $comp['weight'], 2) ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <div style="margin-top:14px; border-top:1px solid #eee; padding-top:12px;">
          <div style="font-size:0.8rem; color:#666; margin-bottom:6px;">
            Active policy chain: <strong><?= htmlspecialchars($result['policy']) ?></strong>
          </div>
          <?php if ($result['letter']): ?>
          <div style="font-size:0.9rem; margin-bottom:8px;">
            Letter Grade: <strong style="font-size:1.1rem;"><?= $result['letter'] ?></strong>
          </div>
          <?php endif; ?>
          <div class="grade-bar-wrap">
            <div style="display:flex; justify-content:space-between; margin-bottom:4px;">
              <span style="font-size:0.85rem;">Final Grade</span>
              <span style="font-size:1rem; font-weight:bold;"><?= $result['final'] ?> / 100</span>
            </div>
            <div class="grade-bar">
              <div class="grade-fill" style="width:<?= min(100,$result['final']) ?>%"></div>
            </div>
          </div>
        </div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="tab-panel <?= $tab==='notifs'?'active':'' ?>" id="panel-notifs">
    <div class="card">
      <h3>Notifications <span class="pattern-note">Observer Pattern - output</span></h3>
      <p class="section-desc">
        These notifications were written by <strong>DatabaseNotifier</strong> and
        <strong>AttendanceNotifier</strong> (the Concrete Observers). Inserted automatically
        when an instructor posts an announcement or marks you absent.
      </p>
      <?php if ($unreadCount > 0): ?>
        <a href="?mark_read=1&tab=notifs" style="font-size:0.82rem;">Mark all as read</a>
      <?php endif; ?>
      <?php if (empty($notifications)): ?>
        <p class="empty">No notifications yet.</p>
      <?php else: ?>
        <?php foreach ($notifications as $n): ?>
        <div class="notif-item">
          <div class="notif-dot <?= $n['is_read']?'read':'' ?>"></div>
          <div>
            <div style="font-size:0.87rem;"><?= htmlspecialchars($n['message']) ?></div>
            <div class="notif-time"><?= $n['created_at'] ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </div>

  <?php endif; ?>

</div>

<script>
var currentTab = '<?= $tab ?>';

function setTab(name) {
  document.querySelectorAll('.tab-panel').forEach(function(p) {
    p.classList.remove('active');
  });
  document.querySelectorAll('.tab-btn').forEach(function(b) {
    b.classList.remove('active');
  });

  var panel = document.getElementById('panel-' + name);
  if (panel) panel.classList.add('active');

  document.querySelectorAll('.tab-btn').forEach(function(b) {
    if (b.getAttribute('onclick') === "setTab('" + name + "')") {
      b.classList.add('active');
    }
  });

  currentTab = name;
}
</script>

</body>
</html>
