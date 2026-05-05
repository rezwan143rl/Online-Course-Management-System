<?php
// Decorator Pattern - Grading policies
// the idea is you start with a base grade calculator (WeightedGrade)
// and then wrap it with extra policies at runtime
// e.g. new CurveDecorator(new DropLowestDecorator(new WeightedGrade()))
// each wrapper adds something on top without changing what's inside
//
// from the Sandwich and Window examples in class - same wrapping idea
// WeightedGrade = BasicSandwich/SimpleWindow (the base)
// CurveDecorator, DropLowestDecorator, BonusDecorator = the wrappers
//
// why not just if-else? because then adding a new policy means
// editing the existing class. with decorators you just add a new class.
// thats Open/Closed Principle - open for extension, closed for modification


// GradeCalculator - interface both base and all decorators implement
// this is what lets them stack - they all look the same to the caller
interface GradeCalculator
{
    public function calculate(array $components): float;
    public function describe(): string; // shows which policies are active
}


// WeightedGrade - the base, no policies applied
// just multiplies marks by weight for each grade item and sums them up
// e.g. midterm 78 * 0.20 = 15.6, assignment 85 * 0.30 = 25.5, etc
class WeightedGrade implements GradeCalculator
{
    public function calculate(array $components): float
    {
        $total = 0.0;
        foreach ($components as $c) {
            $total += (float)$c['marks'] * (float)$c['weight'];
        }
        return round($total, 2);
    }

    public function describe(): string
    {
        return 'Weighted Average';
    }
}


// GradeDecorator - abstract base for all decorators
// holds a reference to the wrapped object ($wrapped)
// could be WeightedGrade or another decorator stacked on top
abstract class GradeDecorator implements GradeCalculator
{
    protected GradeCalculator $wrapped;

    public function __construct(GradeCalculator $wrapped)
    {
        $this->wrapped = $wrapped;
    }
}


// CurveDecorator - adds +5 marks to whatever the inner calculator returns
// calls $this->wrapped->calculate() first to get the base result
// then adds the curve on top, caps at 100
class CurveDecorator extends GradeDecorator
{
    private float $curveAmount;

    public function __construct(GradeCalculator $wrapped, float $curveAmount = 5.0)
    {
        parent::__construct($wrapped);
        $this->curveAmount = $curveAmount;
    }

    public function calculate(array $components): float
    {
        // get whatever the inner object calculated first
        $base = $this->wrapped->calculate($components);
        // then add the curve
        return min(100.0, round($base + $this->curveAmount, 2));
    }

    public function describe(): string
    {
        return $this->wrapped->describe() . ' + Curve (+' . $this->curveAmount . ')';
    }
}


// DropLowestDecorator - removes the lowest scoring component
// then recalculates with the remaining ones
// re-normalizes weights so they still add up to 1.0 after removal
class DropLowestDecorator extends GradeDecorator
{
    public function calculate(array $components): float
    {
        if (count($components) <= 1) {
            return $this->wrapped->calculate($components);
        }

        // sort by marks ascending, remove the first one (lowest)
        usort($components, fn($a, $b) => (float)$a['marks'] <=> (float)$b['marks']);
        array_shift($components);

        // re-normalize weights so they still sum to 1.0
        $totalWeight = array_sum(array_column($components, 'weight'));
        if ($totalWeight > 0) {
            foreach ($components as &$c) {
                $c['weight'] = (float)$c['weight'] / $totalWeight;
            }
        }

        return $this->wrapped->calculate($components);
    }

    public function describe(): string
    {
        return $this->wrapped->describe() . ' + Drop Lowest';
    }
}


// BonusDecorator - adds a small fixed bonus, capped at 100
// added this to show how easy it is to add a new policy
// just a new class, nothing else needed to change
class BonusDecorator extends GradeDecorator
{
    private float $bonus;

    public function __construct(GradeCalculator $wrapped, float $bonus = 2.0)
    {
        parent::__construct($wrapped);
        $this->bonus = $bonus;
    }

    public function calculate(array $components): float
    {
        $base = $this->wrapped->calculate($components);
        return min(100.0, round($base + $this->bonus, 2));
    }

    public function describe(): string
    {
        return $this->wrapped->describe() . ' + Bonus (+' . $this->bonus . ')';
    }
}


// buildGradeCalculator() - called from dashboard.php
// stacks the decorators based on which checkboxes the user ticked
// DropLowest goes first (innermost) so curve applies after the drop
function buildGradeCalculator(
    bool  $applyCurve  = false,
    bool  $dropLowest  = false,
    bool  $applyBonus  = false,
    float $curveAmount = 5.0,
    float $bonusAmount = 2.0
): GradeCalculator {

    // always start with the base
    $calc = new WeightedGrade();

    // wrap with decorators depending on what was selected
    // order matters - drop lowest before curve so curve applies after
    if ($dropLowest) {
        $calc = new DropLowestDecorator($calc);
    }

    if ($applyBonus) {
        $calc = new BonusDecorator($calc, $bonusAmount);
    }

    if ($applyCurve) {
        $calc = new CurveDecorator($calc, $curveAmount);
    }

    return $calc;
}
?>
