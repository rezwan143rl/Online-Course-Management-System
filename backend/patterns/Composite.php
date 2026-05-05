<?php
// Composite Pattern - Course content tree
// a course has sections, sections have items (lectures, quizzes, assignments)
// thats a tree structure. Composite lets us treat sections and items
// the same way even though one has children and the other doesnt
//
// CourseItem = leaf node - single content piece, no children
// CourseSection = composite node - contains items, can have children
// both implement CourseComponent so the display code never needs to
// check "is this a section or an item" - it just calls render()
//
// if render() is called on a section it loops its children and
// calls render() on each one automatically. thats the key behaviour.


// CourseComponent - interface both Section and Item implement
// having the same interface is what allows uniform treatment
interface CourseComponent
{
    public function getTitle(): string;
    public function getType(): string;
    public function getChildCount(): int;
    public function render(): array;
}


// CourseItem - the leaf node
// a single piece of content: lecture, quiz, or assignment
// never has children - getChildCount() always returns 0
// render() just returns its own data, children array is always empty
class CourseItem implements CourseComponent
{
    private string $title;
    private string $type;
    private string $description;

    public function __construct(string $title, string $type, string $description = '')
    {
        $this->title       = $title;
        $this->type        = $type;
        $this->description = $description;
    }

    public function getTitle(): string   { return $this->title; }
    public function getType(): string    { return $this->type; }
    public function getChildCount(): int { return 0; } // leaf has no children

    public function render(): array
    {
        return [
            'nodeType'    => 'item',
            'title'       => $this->title,
            'type'        => $this->type,
            'description' => $this->description,
            'children'    => [] // always empty for a leaf
        ];
    }
}


// CourseSection - the composite node
// a week or topic group that contains items inside it
// add() puts items into the children array
// render() loops children and calls render() on each one -
// this is the recursive part that makes the whole tree work
class CourseSection implements CourseComponent
{
    private string $title;
    private array  $children = [];

    public function __construct(string $title)
    {
        $this->title = $title;
    }

    // add a child - could be a CourseItem or even another CourseSection
    public function add(CourseComponent $component): void
    {
        $this->children[] = $component;
    }

    public function getTitle(): string    { return $this->title; }
    public function getType(): string     { return 'section'; }
    public function getChildCount(): int  { return count($this->children); }
    public function getChildren(): array  { return $this->children; }

    // render() calls render() on every child automatically
    // this is what makes Composite powerful - one call handles the whole tree
    // works the same whether children are items or nested sections
    public function render(): array
    {
        $childData = [];
        foreach ($this->children as $child) {
            $childData[] = $child->render(); // polymorphic - works for any CourseComponent
        }

        return [
            'nodeType'  => 'section',
            'title'     => $this->title,
            'itemCount' => $this->getChildCount(),
            'children'  => $childData
        ];
    }
}


// CourseStructureLoader - loads sections and items from DB
// builds the Composite tree by creating Section and Item objects
// and attaching items to their sections with add()
class CourseStructureLoader
{
    private mysqli $db;

    public function __construct(mysqli $db)
    {
        $this->db = $db;
    }

    // returns array of CourseSection objects with their items already attached
    public function load(int $courseId): array
    {
        $sections = [];

        $stmt = $this->db->prepare(
            "SELECT * FROM course_sections
             WHERE course_id = ? ORDER BY display_order ASC"
        );
        $stmt->bind_param('i', $courseId);
        $stmt->execute();
        $sectionRows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();

        foreach ($sectionRows as $sRow) {
            // create the composite node for this section
            $section = new CourseSection($sRow['title']);

            // load the items for this section
            $stmt2 = $this->db->prepare(
                "SELECT * FROM course_items
                 WHERE section_id = ? ORDER BY display_order ASC"
            );
            $stmt2->bind_param('i', $sRow['id']);
            $stmt2->execute();
            $itemRows = $stmt2->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt2->close();

            // attach each item as a leaf to this section
            foreach ($itemRows as $iRow) {
                $section->add(new CourseItem(
                    $iRow['title'],
                    $iRow['item_type'],
                    $iRow['description'] ?? ''
                ));
            }

            $sections[] = $section;
        }

        return $sections;
    }
}
?>
