<?php
/*
 * Grading scale. Defaults to the WAEC / NECO nine-point scale used by most
 * Nigerian secondary schools. Edit the rows below to use your school's scale
 * (minimum mark, grade, remark), highest band first.
 */
function grade_scale(): array
{
    return [
        [75, 'A1', 'Excellent'],
        [70, 'B2', 'Very Good'],
        [65, 'B3', 'Good'],
        [60, 'C4', 'Credit'],
        [55, 'C5', 'Credit'],
        [50, 'C6', 'Credit'],
        [45, 'D7', 'Pass'],
        [40, 'E8', 'Pass'],
        [0,  'F9', 'Fail'],
    ];
}

/** ['grade' => 'B3', 'remark' => 'Good'] for a total out of 100. */
function grade_for(float $total): array
{
    foreach (grade_scale() as [$min, $grade, $remark]) {
        if ($total >= $min) {
            return ['grade' => $grade, 'remark' => $remark];
        }
    }
    return ['grade' => 'F9', 'remark' => 'Fail'];
}

/** Maximum marks for each part of a score, from Settings. */
function score_limits(): array
{
    return [
        'ca1'  => (float) setting('ca1_max', '20'),
        'ca2'  => (float) setting('ca2_max', '20'),
        'exam' => (float) setting('exam_max', '60'),
    ];
}

/** Suggested principal's remark from a student's average. */
function suggested_principal_remark(float $average): string
{
    if ($average >= 75) return 'An excellent result. Keep it up!';
    if ($average >= 65) return 'A very good result. Well done.';
    if ($average >= 55) return 'A good result. You can do even better.';
    if ($average >= 45) return 'A fair result. More effort is needed.';
    return 'A weak result. Serious improvement is required next term.';
}
