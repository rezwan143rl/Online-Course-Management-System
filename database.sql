-- ============================================================
-- Course Management System - Full Database
-- 
-- HOW TO IMPORT:
--   1. Open phpMyAdmin at http://localhost/phpmyadmin
--   2. Click "New" on the left sidebar
--   3. Database name: course_system   (use this EXACT name)
--   4. Click Create
--   5. Click the "Import" tab
--   6. Choose this file -> click Go
--
-- IMPORTANT: The PHP code (Database.php) connects to "course_system".
-- If you use a different database name it will not work.
-- ============================================================

CREATE DATABASE IF NOT EXISTS course_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE course_system;

-- ------------------------------------------------------------
-- users
-- role is read by UserFactory to decide which class to create
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    userid    INT AUTO_INCREMENT PRIMARY KEY,
    name      VARCHAR(100) NOT NULL,
    email     VARCHAR(100) NOT NULL UNIQUE,
    password  VARCHAR(255) NOT NULL,
    role      ENUM('admin','instructor','student') NOT NULL DEFAULT 'student'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- courses
-- Only admin can create a course (enforced in PHP, not DB)
-- instructor_id ties the course to one instructor
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS courses (
    courseid      INT AUTO_INCREMENT PRIMARY KEY,
    title         VARCHAR(150) NOT NULL,
    description   TEXT,
    instructor_id INT NOT NULL,
    FOREIGN KEY (instructor_id) REFERENCES users(userid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- prerequisites
-- course_id requires required_course_id to be passed first
-- Proxy checks this before granting a student access
-- NOTE: Must come after courses table (foreign key dependency)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS prerequisites (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    course_id          INT NOT NULL,
    required_course_id INT NOT NULL,
    FOREIGN KEY (course_id)          REFERENCES courses(courseid) ON DELETE CASCADE,
    FOREIGN KEY (required_course_id) REFERENCES courses(courseid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- course_sections
-- A course is broken into sections (Composite: the container node)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS course_sections (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    course_id     INT NOT NULL,
    title         VARCHAR(150) NOT NULL,
    display_order INT DEFAULT 0,
    FOREIGN KEY (course_id) REFERENCES courses(courseid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- course_items
-- Individual content pieces inside a section (Composite: the leaf node)
-- item_type: lecture | assignment | quiz
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS course_items (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    section_id    INT NOT NULL,
    title         VARCHAR(150) NOT NULL,
    item_type     ENUM('lecture','assignment','quiz') DEFAULT 'lecture',
    description   TEXT,
    display_order INT DEFAULT 0,
    FOREIGN KEY (section_id) REFERENCES course_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- enrollments
-- Proxy checks this first before allowing student course access
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS enrollments (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id  INT NOT NULL,
    UNIQUE KEY uq_enroll (student_id, course_id),
    FOREIGN KEY (student_id) REFERENCES users(userid) ON DELETE CASCADE,
    FOREIGN KEY (course_id)  REFERENCES courses(courseid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- attendance
-- Proxy checks attendance percentage before granting access
-- Observer fires a notification when a student is marked absent
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id  INT NOT NULL,
    class_date DATE NOT NULL,
    status     ENUM('present','absent') DEFAULT 'present',
    UNIQUE KEY uq_attend (student_id, course_id, class_date),
    FOREIGN KEY (student_id) REFERENCES users(userid) ON DELETE CASCADE,
    FOREIGN KEY (course_id)  REFERENCES courses(courseid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- grade_items
-- Represents one graded component: midterm, assignment, final
-- weight is a decimal 0.0 to 1.0 (e.g. 0.30 = 30%)
-- Decorator wraps these to apply grading policies
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grade_items (
    id        INT AUTO_INCREMENT PRIMARY KEY,
    course_id INT NOT NULL,
    name      VARCHAR(100) NOT NULL,
    weight    FLOAT NOT NULL,
    FOREIGN KEY (course_id) REFERENCES courses(courseid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- grades
-- Actual marks a student received for a grade_item
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS grades (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    student_id    INT NOT NULL,
    grade_item_id INT NOT NULL,
    marks         FLOAT NOT NULL,
    UNIQUE KEY uq_grade (student_id, grade_item_id),
    FOREIGN KEY (student_id)    REFERENCES users(userid) ON DELETE CASCADE,
    FOREIGN KEY (grade_item_id) REFERENCES grade_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- announcements
-- Written by instructor, triggers Observer notification to all students
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcements (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    course_id     INT NOT NULL,
    instructor_id INT NOT NULL,
    message       TEXT NOT NULL,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (course_id)     REFERENCES courses(courseid) ON DELETE CASCADE,
    FOREIGN KEY (instructor_id) REFERENCES users(userid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- notifications
-- Written by DatabaseNotifier (the Concrete Observer)
-- Students read this in their dashboard
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NOT NULL,
    course_id  INT NOT NULL,
    message    TEXT NOT NULL,
    is_read    TINYINT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES users(userid) ON DELETE CASCADE,
    FOREIGN KEY (course_id)  REFERENCES courses(courseid) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ============================================================
-- SEED DATA
-- ============================================================

INSERT INTO users (name, email, password, role) VALUES
('Admin',         'admin@test.com', '123', 'admin'),
('Dr. Rahman',    'inst@test.com',  '123', 'instructor'),
('Alice',         'alice@test.com', '123', 'student'),
('Bob',           'bob@test.com',   '123', 'student');

-- Admin creates two courses, assigns Dr. Rahman (userid=2)
INSERT INTO courses (title, description, instructor_id) VALUES
('Fundamentals of Programming', 'Variables, loops, functions, basics.',        2),
('Object Oriented Programming', 'Classes, inheritance, design patterns.',       2);

-- OOP requires Fundamentals to be passed first
INSERT INTO prerequisites (course_id, required_course_id) VALUES (2, 1);

-- Sections for course 1 (Composite containers)
INSERT INTO course_sections (course_id, title, display_order) VALUES
(1, 'Week 1 - Introduction',     1),
(1, 'Week 2 - Control Flow',     2);

-- Items inside sections (Composite leaves)
INSERT INTO course_items (section_id, title, item_type, description, display_order) VALUES
(1, 'Lecture 1 - Hello World',       'lecture',    'First program, print statements.',     1),
(1, 'Quiz 1 - Variables',            'quiz',       'Short quiz on variable types.',         2),
(2, 'Lecture 2 - Loops',             'lecture',    'For loops and while loops.',            1),
(2, 'Assignment 1 - Loop Problems',  'assignment', 'Write 3 programs using loops.',         2);

-- Sections for course 2
INSERT INTO course_sections (course_id, title, display_order) VALUES
(2, 'Week 1 - Classes and Objects',  1),
(2, 'Week 2 - Inheritance',          2);

INSERT INTO course_items (section_id, title, item_type, description, display_order) VALUES
(3, 'Lecture 1 - What is a Class',   'lecture',    'Class syntax, constructors.',          1),
(3, 'Quiz 1 - OOP Basics',           'quiz',       'Quiz on class and object concepts.',   2),
(4, 'Lecture 2 - Inheritance',       'lecture',    'extends keyword, method overriding.',  1),
(4, 'Assignment 2 - Shape Hierarchy','assignment', 'Build a class hierarchy for shapes.',  2);

-- Grade items for course 1
INSERT INTO grade_items (course_id, name, weight) VALUES
(1, 'Quiz 1',       0.20),
(1, 'Assignment 1', 0.30),
(1, 'Final Exam',   0.50);

-- Grade items for course 2
INSERT INTO grade_items (course_id, name, weight) VALUES
(2, 'Quiz 1',       0.20),
(2, 'Assignment 2', 0.30),
(2, 'Final Exam',   0.50);

-- Alice enrolled in course 1 only
INSERT INTO enrollments (student_id, course_id) VALUES (3, 1);

-- Alice's grades for course 1
-- Weighted average: (78*0.20) + (85*0.30) + (80*0.50) = 15.6+25.5+40 = 81.1
INSERT INTO grades (student_id, grade_item_id, marks) VALUES
(3, 1, 78),
(3, 2, 85),
(3, 3, 80);

-- Alice's attendance for course 1 (4 present out of 5 = 80%, passes 75% gate)
INSERT INTO attendance (student_id, course_id, class_date, status) VALUES
(3, 1, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'present'),
(3, 1, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'present'),
(3, 1, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'absent'),
(3, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'present'),
(3, 1, CURDATE(),                            'present');

-- Bob enrolled in course 1, but only 2/5 attendance (40% - Proxy will BLOCK him)
INSERT INTO enrollments (student_id, course_id) VALUES (4, 1);
INSERT INTO grades (student_id, grade_item_id, marks) VALUES
(4, 1, 60), (4, 2, 55), (4, 3, 58);
INSERT INTO attendance (student_id, course_id, class_date, status) VALUES
(4, 1, DATE_SUB(CURDATE(), INTERVAL 4 DAY), 'absent'),
(4, 1, DATE_SUB(CURDATE(), INTERVAL 3 DAY), 'absent'),
(4, 1, DATE_SUB(CURDATE(), INTERVAL 2 DAY), 'absent'),
(4, 1, DATE_SUB(CURDATE(), INTERVAL 1 DAY), 'present'),
(4, 1, CURDATE(),                            'present');

-- A welcome announcement for course 1
INSERT INTO announcements (course_id, instructor_id, message) VALUES
(1, 2, 'Welcome to Fundamentals of Programming. First class is Monday.');
