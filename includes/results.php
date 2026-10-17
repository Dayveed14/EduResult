<?php
/*
 * Result computation: totals, averages, class positions and subject statistics.
 * Everything a broadsheet or report card shows comes from class_results().
 */

/**
 * Rank values from highest to lowest using "1224" ranking (ties share a position
 * and the next position is skipped), the way schools usually do it.
 *
 * @param array $values  key => number
 * @return array key => position
 */
function rank_desc(array $values): array
{
    arsort($values, SORT_NUMERIC);
    $positions = [];
    $rank = 0;
    $seen = 0;
    $previous = null;
    foreach ($values as $key => $value) {
        $seen++;
        if ($previous === null || abs($value - $previous) > 0.00001) {
            $rank = $seen;
            $previous = $value;
        }
        $positions[$key] = $rank;
    }
    return $positions;
}

/** Subjects for a class: what the class offers plus anything already scored. */
function class_subject_list(int $classId, ?int $termId = null): array
{
    $sql = 'SELECT sb.id, sb.name FROM class_subjects cs JOIN subjects sb ON sb.id = cs.subject_id WHERE cs.class_id = ?';
    $params = [$classId];
    if ($termId) {
        $sql .= ' UNION SELECT sb.id, sb.name FROM scores s JOIN subjects sb ON sb.id = s.subject_id
                  WHERE s.class_id = ? AND s.term_id = ?';
        $params[] = $classId;
        $params[] = $termId;
    }
    $st = db()->prepare("SELECT DISTINCT id, name FROM ($sql) x ORDER BY name");
    $st->execute($params);
    $out = [];
    foreach ($st as $r) {
        $out[(int) $r['id']] = $r['name'];
    }
    return $out;
}

function class_results(int $termId, int $classId): array
{
    $subjects = class_subject_list($classId, $termId);

    // Everyone with a score in this class and term, plus (for the current term)
    // active students sitting in the class now, so blank rows show on broadsheets.
    $st = db()->prepare(
        'SELECT DISTINCT st.* FROM students st
         WHERE st.id IN (SELECT student_id FROM scores WHERE term_id = ? AND class_id = ?)
            OR (st.class_id = ? AND st.status = \'active\'
                AND EXISTS (SELECT 1 FROM terms t WHERE t.id = ? AND t.is_current = 1))
         ORDER BY st.surname, st.first_name'
    );
    $st->execute([$termId, $classId, $classId, $termId]);
    $students = [];
    foreach ($st as $row) {
        $students[(int) $row['id']] = [
            'student' => $row,
            'scores'  => [],
            'total'   => 0.0,
            'count'   => 0,
            'average' => null,
            'position' => null,
        ];
    }

    $st = db()->prepare('SELECT * FROM scores WHERE term_id = ? AND class_id = ?');
    $st->execute([$termId, $classId]);
    $bySubject = [];
    foreach ($st as $s) {
        $sid = (int) $s['student_id'];
        if (!isset($students[$sid])) {
            continue;
        }
        if ($s['ca1'] === null && $s['ca2'] === null && $s['exam'] === null) {
            continue; // an empty row counts as "not taken"
        }
        $total = (float) $s['total'];
        $s += grade_for($total);
        $students[$sid]['scores'][(int) $s['subject_id']] = $s;
        $students[$sid]['total'] += $total;
        $students[$sid]['count']++;
        $bySubject[(int) $s['subject_id']][$sid] = $total;
    }

    $averages = [];
    foreach ($students as $sid => &$r) {
        if ($r['count'] > 0) {
            $r['average'] = round($r['total'] / $r['count'], 2);
            $averages[$sid] = $r['average'];
        }
    }
    unset($r);

    foreach (rank_desc($averages) as $sid => $pos) {
        $students[$sid]['position'] = $pos;
    }

    $subjectStats = [];
    foreach ($bySubject as $subjectId => $totals) {
        $subjectStats[$subjectId] = [
            'highest'   => max($totals),
            'lowest'    => min($totals),
            'average'   => round(array_sum($totals) / count($totals), 2),
            'positions' => rank_desc($totals),
            'count'     => count($totals),
        ];
    }

    return [
        'subjects'      => $subjects,
        'students'      => $students,
        'subject_stats' => $subjectStats,
        'out_of'        => count($averages),
    ];
}

/** The class a student was in for a given term (from their scores), else their current class. */
function class_for_term(int $studentId, int $termId): ?int
{
    $st = db()->prepare('SELECT class_id FROM scores WHERE student_id = ? AND term_id = ? LIMIT 1');
    $st->execute([$studentId, $termId]);
    $c = $st->fetchColumn();
    if ($c) {
        return (int) $c;
    }
    $s = find_student($studentId);
    return $s ? (int) $s['class_id'] : null;
}

/** Everything the report card needs for one student and term. */
function report_card_data(int $studentId, int $termId): ?array
{
    $student = find_student($studentId);
    $term = find_term($termId);
    if (!$student || !$term) {
        return null;
    }
    $classId = class_for_term($studentId, $termId);
    $class = find_class($classId);
    $results = class_results($termId, $classId);
    $mine = $results['students'][$studentId] ?? null;

    $st = db()->prepare('SELECT * FROM term_reports WHERE student_id = ? AND term_id = ?');
    $st->execute([$studentId, $termId]);
    $remarks = $st->fetch() ?: [];

    $classTeacher = null;
    if ($class && $class['class_teacher_id']) {
        $st = db()->prepare('SELECT full_name FROM users WHERE id = ?');
        $st->execute([$class['class_teacher_id']]);
        $classTeacher = $st->fetchColumn() ?: null;
    }

    $rows = [];
    foreach ($results['subjects'] as $subjectId => $subjectName) {
        $score = $mine['scores'][$subjectId] ?? null;
        if (!$score) {
            continue;
        }
        $stats = $results['subject_stats'][$subjectId];
        $rows[] = [
            'subject'  => $subjectName,
            'ca1'      => $score['ca1'],
            'ca2'      => $score['ca2'],
            'exam'     => $score['exam'],
            'total'    => (float) $score['total'],
            'grade'    => $score['grade'],
            'remark'   => $score['remark'],
            'position' => $stats['positions'][$studentId] ?? null,
            'highest'  => $stats['highest'],
            'lowest'   => $stats['lowest'],
            'class_average' => $stats['average'],
        ];
    }

    return [
        'student'       => $student,
        'term'          => $term,
        'class'         => $class,
        'class_teacher' => $classTeacher,
        'rows'          => $rows,
        'total'         => $mine['total'] ?? 0,
        'count'         => $mine['count'] ?? 0,
        'average'       => $mine['average'] ?? null,
        'position'      => $mine['position'] ?? null,
        'out_of'        => $results['out_of'],
        'remarks'       => $remarks,
        'limits'        => score_limits(),
    ];
}

/** Score-entry progress for the dashboard: per class-subject, how many students have scores. */
function score_progress(int $termId, array $assignments): array
{
    $st = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM students WHERE class_id = ? AND status = \'active\') AS enrolled,
                (SELECT COUNT(*) FROM scores WHERE term_id = ? AND class_id = ? AND subject_id = ?
                    AND (ca1 IS NOT NULL OR ca2 IS NOT NULL OR exam IS NOT NULL)) AS scored'
    );
    foreach ($assignments as &$a) {
        $st->execute([$a['class_id'], $termId, $a['class_id'], $a['subject_id']]);
        $a += $st->fetch();
    }
    return $assignments;
}
