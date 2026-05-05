<?php
// Decorator.php - Grading system using the Decorator design pattern

// Structure:
//       GradeCalculator (interface)
//       WeightedGrade        <- the base, always present
//       GradeDecorator       <- abstract, holds the wrapped object
//       CurveDecorator       <- adds flat marks to the result
//       DropLowestDecorator  <- removes the worst grade before calculating
//       BonusDecorator       <- adds a small flat bonus
//       PenaltyDecorator     <- subtracts marks (e.g. late submission)
//       MaxScoreDecorator    <- caps the result at a ceiling below 100
//       LetterGradeDecorator <- converts the final number to A/B/C/D/F



// GradeCalculator - the interface every class here follows
// calculate() takes an array of graded components and returns a float.
// describe() returns a readable string of the active policy chain, e.g. "Weighted Average + Drop Lowest + Curve (+5)"

interface GradeCalculator
{
    // $components: array of ['name' => ..., 'marks' => ..., 'weight' => ...]
    public function calculate(array $components): float;
    public function describe(): string;
}


// WeightedGrade - the base calculator (always the innermost layer)

// Computes sum of (marks * weight) for each component.
// Example: Quiz(78, 20%) + Assignment(85, 30%) + Final(80, 50%) = 15.6 + 25.5 + 40.0 = 81.1
// This class never changes. All decorators wrap around it.

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
// Stores the wrapped object in $wrapped. Every concrete decorator extends this and defines its own calculate() and describe().
// The key relationship here:
//   IS a GradeCalculator -> can be passed anywhere one is expected
//   HAS a GradeCalculator -> can wrap any other calculator

abstract class GradeDecorator implements GradeCalculator
{
    protected GradeCalculator $wrapped;

    public function __construct(GradeCalculator $wrapped)
    {
        $this->wrapped = $wrapped;
    }
}


// CurveDecorator
// Adds a flat amount to the final grade (default +5), capped at 100.
// Post-processor: lets the full inner chain run first, then adjusts.

class CurveDecorator extends GradeDecorator
{
    private float $amount;

    public function __construct(GradeCalculator $wrapped, float $amount = 5.0)
    {
        parent::__construct($wrapped);
        $this->amount = $amount;
    }

    public function calculate(array $components): float
    {
        $base = $this->wrapped->calculate($components);
        return min(100.0, round($base + $this->amount, 2));
    }

    public function describe(): string
    {
        return $this->wrapped->describe() . ' + Curve (+' . $this->amount . ')';
    }
}



// DropLowestDecorator
// Removes the lowest-scoring component before passing the array. Pre-processor, because modifies the input, not output.
// After dropping one item, the remaining weights no longer sum to 1.0, so re-normalize-- drop a 20% item, leaving 30% and 50%, 
// (sum = 0.80). Dividing each by 0.80 gives 0.375 and 0.625 (sum = 1.0).


class DropLowestDecorator extends GradeDecorator
{
    public function calculate(array $components): float
    {
        if (count($components) <= 1) {
            return $this->wrapped->calculate($components);
        }

        usort($components, fn($a, $b) => (float)$a['marks'] <=> (float)$b['marks']);
        array_shift($components);

        $total = array_sum(array_column($components, 'weight'));
        if ($total > 0) {
            foreach ($components as &$c) {
                $c['weight'] = (float)$c['weight'] / $total;
            }
            unset($c);
        }

        return $this->wrapped->calculate($components);
    }

    public function describe(): string
    {
        return $this->wrapped->describe() . ' + Drop Lowest';
    }
}



// BonusDecorator
// Adds a small flat bonus to the final grade (default +2), capped at 100.

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


// PenaltyDecorator
// Subtracts marks from the final grade (default -5), floored at 0. (for late submissions or policy violations.)

class PenaltyDecorator extends GradeDecorator
{
    private float  $penalty;
    private string $reason;

    public function __construct(GradeCalculator $wrapped, float $penalty = 5.0, string $reason = 'Late Submission')
    {
        parent::__construct($wrapped);
        $this->penalty = $penalty;
        $this->reason  = $reason;
    }

    public function calculate(array $components): float
    {
        $base = $this->wrapped->calculate($components);
        return max(0.0, round($base - $this->penalty, 2));
    }

    public function describe(): string
    {
        return $this->wrapped->describe() . ' - Penalty (-' . $this->penalty . ', ' . $this->reason . ')';
    }
}


// MaxScoreDecorator

// Sets an upper limit the grade cannot exceed.

// Example: a late assignment can earn at most 70/100.
// Even if the student's weighted score is 85, this brings it to 70.

class MaxScoreDecorator extends GradeDecorator
{
    private float $cap;

    public function __construct(GradeCalculator $wrapped, float $cap = 70.0)
    {
        parent::__construct($wrapped);
        $this->cap = $cap;
    }

    public function calculate(array $components): float
    {
        $base = $this->wrapped->calculate($components);
        return min($this->cap, $base);
    }

    public function describe(): string
    {
        return $this->wrapped->describe() . ' + Max Score (capped at ' . $this->cap . ')';
    }
}


// LetterGradeDecorator
// Converts the numeric grade to a letter grade: A/B/C/D/F.
// Grade scale: 90+ = A, 80+ = B, 70+ = C, 60+ = D, below 60 = F

class LetterGradeDecorator extends GradeDecorator
{
    public function calculate(array $components): float
    {
        return $this->wrapped->calculate($components);
    }

    public function getLetter(array $components): string
    {
        $grade = $this->calculate($components);
        if ($grade >= 90) return 'A';
        if ($grade >= 80) return 'B';
        if ($grade >= 70) return 'C';
        if ($grade >= 60) return 'D';
        return 'F';
    }

    public function describe(): string
    {
        return $this->wrapped->describe() . ' + Letter Grade';
    }
}


// buildGradeCalculator()


function buildGradeCalculator(
    bool  $applyCurve   = false,
    bool  $dropLowest   = false,
    bool  $applyBonus   = false,
    bool  $applyPenalty = false,
    bool  $applyMax     = false,
    bool  $applyLetter  = false,
    float $curveAmount  = 5.0,
    float $bonusAmount  = 2.0,
    float $penaltyAmount = 5.0,
    float $maxScore     = 70.0
): GradeCalculator {

    $calc = new WeightedGrade();

    if ($dropLowest)   $calc = new DropLowestDecorator($calc);
    if ($applyBonus)   $calc = new BonusDecorator($calc, $bonusAmount);
    if ($applyPenalty) $calc = new PenaltyDecorator($calc, $penaltyAmount);
    if ($applyMax)     $calc = new MaxScoreDecorator($calc, $maxScore);
    if ($applyCurve)   $calc = new CurveDecorator($calc, $curveAmount);
    if ($applyLetter)  $calc = new LetterGradeDecorator($calc);

    return $calc;
}
