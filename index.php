<?php
// Student Grading System - Beginner-friendly single-file CRUD application.
// Local database: XAMPP MariaDB on port 3307.
require_once __DIR__ . '/auth.php';
require_admin_login();
require_once __DIR__ . '/db.php';

function clean($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

// A security token prevents another website from submitting changes for the user.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}

$error = '';
$editing = false;
$editId = 0;
$form = [
    'student_number' => '', 'full_name' => '', 'subject' => '',
    'prelim' => '', 'midterm' => '', 'final_exam' => ''
];

// CREATE, UPDATE and DELETE happen only when a form is submitted.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_token'], (string) ($_POST['csrf_token'] ?? ''))) {
        $error = 'Invalid form token. Please refresh the page and try again.';
    } else {
        $action = (string) ($_POST['action'] ?? '');

        if ($action === 'delete') {
            $id = (int) ($_POST['id'] ?? 0);
            if ($id > 0) {
                $stmt = $conn->prepare('DELETE FROM student_grades WHERE id = ?');
                $stmt->bind_param('i', $id);
                $stmt->execute();
                $stmt->close();
                header('Location: index.php?status=deleted');
                exit;
            }
            $error = 'Invalid record selected.';
        }

        if ($action === 'save') {
            $editId = (int) ($_POST['id'] ?? 0);
            $editing = $editId > 0;

            foreach ($form as $key => $unused) {
                $form[$key] = trim((string) ($_POST[$key] ?? ''));
            }

            if ($form['student_number'] === '' || $form['full_name'] === '' || $form['subject'] === '') {
                $error = 'Please fill in the student number, full name and subject.';
            } elseif (!is_numeric($form['prelim']) || !is_numeric($form['midterm']) || !is_numeric($form['final_exam'])) {
                $error = 'Please enter a valid number for each grade.';
            } else {
                $prelim = (float) $form['prelim'];
                $midterm = (float) $form['midterm'];
                $finalExam = (float) $form['final_exam'];

                if ($prelim < 1 || $prelim > 5 || $midterm < 1 || $midterm > 5 || $finalExam < 1 || $finalExam > 5) {
                    $error = 'Grades must be between 1.00 and 5.00.';
                } else {
                    $studentNumber = $form['student_number'];
                    $fullName = $form['full_name'];
                    $subject = $form['subject'];

                    try {
                        if ($editing) {
                            // UPDATE: edit an existing student grade record.
                            $stmt = $conn->prepare('UPDATE student_grades SET student_number = ?, full_name = ?, subject = ?, prelim = ?, midterm = ?, final_exam = ? WHERE id = ?');
                            $stmt->bind_param('sssdddi', $studentNumber, $fullName, $subject, $prelim, $midterm, $finalExam, $editId);
                        } else {
                            // CREATE: add a new student grade record.
                            $stmt = $conn->prepare('INSERT INTO student_grades (student_number, full_name, subject, prelim, midterm, final_exam) VALUES (?, ?, ?, ?, ?, ?)');
                            $stmt->bind_param('sssddd', $studentNumber, $fullName, $subject, $prelim, $midterm, $finalExam);
                        }

                        $stmt->execute();
                        $stmt->close();
                        header('Location: index.php?status=' . ($editing ? 'updated' : 'added'));
                        exit;
                    } catch (mysqli_sql_exception $e) {
                        $error = $e->getCode() === 1062
                            ? 'This student number already exists. Please use a different number.'
                            : 'Could not save the record. Please try again.';
                    }
                }
            }
        }
    }
}

// Load record information when the Edit button is clicked.
if ($_SERVER['REQUEST_METHOD'] !== 'POST' && isset($_GET['edit'])) {
    $editId = (int) $_GET['edit'];
    if ($editId > 0) {
        $stmt = $conn->prepare('SELECT * FROM student_grades WHERE id = ?');
        $stmt->bind_param('i', $editId);
        $stmt->execute();
        $record = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($record) {
            $editing = true;
            foreach ($form as $key => $unused) {
                $form[$key] = (string) $record[$key];
            }
        } else {
            $error = 'Record not found.';
        }
    }
}

// READ: display the student records from MySQL.
$students = $conn->query('SELECT * FROM student_grades ORDER BY id DESC')->fetch_all(MYSQLI_ASSOC);
$total = count($students);
$passed = 0;
foreach ($students as $student) {
    $average = ((float) $student['prelim'] + (float) $student['midterm'] + (float) $student['final_exam']) / 3;
    if ($average <= 3.00) {
        $passed++;
    }
}
$failed = $total - $passed;

// Dashboard insights are calculated from the existing table; no database changes required.
$subjectStats = [];
$gradeSum = 0;
foreach ($students as $student) {
    $average = ((float)$student['prelim'] + (float)$student['midterm'] + (float)$student['final_exam']) / 3;
    $subjectName = (string)$student['subject'];
    if (!isset($subjectStats[$subjectName])) {
        $subjectStats[$subjectName] = ['count' => 0, 'passed' => 0, 'sum' => 0.0];
    }
    $subjectStats[$subjectName]['count']++;
    $subjectStats[$subjectName]['sum'] += $average;
    if ($average <= 3.00) {
        $subjectStats[$subjectName]['passed']++;
    }
    $gradeSum += $average;
}
ksort($subjectStats, SORT_NATURAL | SORT_FLAG_CASE);
$subjectCount = count($subjectStats);
$overallAverage = $total ? $gradeSum / $total : null;
$passRate = $total ? round($passed * 100 / $total) : 0;

// Download the displayed records as CSV for a local backup or class demo.
// For spreadsheets, neutralize cells that could be interpreted as formulas.
if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="student_grades_export.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for spreadsheet software.
    fputcsv($out, ['Student Number', 'Full Name', 'Subject', 'Prelim', 'Midterm', 'Final', 'Average', 'Remarks']);
    $safeCsv = function ($value) {
        $s = (string)$value;
        if (preg_match('/^[\s\x00-\x1F]*[=+@-]/u', $s)) {
            return "'" . $s;
        }
        return $s;
    };
    foreach ($students as $student) {
        $avg = ((float)$student['prelim'] + (float)$student['midterm'] + (float)$student['final_exam']) / 3;
        fputcsv($out, [
            $safeCsv($student['student_number']),
            $safeCsv($student['full_name']),
            $safeCsv($student['subject']),
            $student['prelim'], $student['midterm'], $student['final_exam'],
            number_format($avg, 2, '.', ''),
            $avg <= 3.00 ? 'PASSED' : 'FAILED'
        ]);
    }
    fclose($out);
    exit;
}


$successMessages = [
    'added' => 'Student added successfully!',
    'updated' => 'Student updated successfully!',
    'deleted' => 'Student deleted successfully!'
];
$status = (string) ($_GET['status'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Student Grading System | Academic Dashboard</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    :root { --navy:#0f172a; --navy2:#182540; --accent:#4f46e5; --soft:#f4f6fc; --line:#e5eaf4; --ink:#19233a; }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body { margin:0; background:var(--soft); color:var(--ink); font-family:system-ui,-apple-system,"Segoe UI",sans-serif; }
    .side-nav { width:252px; position:fixed; inset:0 auto 0 0; z-index:30; background:var(--navy); color:#d5deee; display:flex; flex-direction:column; }
    .brand { display:flex; align-items:center; gap:12px; padding:27px 22px 26px; border-bottom:1px solid rgba(255,255,255,.09); }
    .brand-icon { display:grid; place-items:center; flex:none; width:43px; height:43px; border-radius:13px; background:#555ce9; color:#fff; font-size:20px; font-weight:800; }
    .brand-title { color:#fff; font-size:16px; line-height:1.25; font-weight:750; }
    .brand-sub { color:#9eaccb; font-size:11px; letter-spacing:.04em; text-transform:uppercase; margin-top:3px; }
    .nav-caption { font-size:10px; font-weight:700; letter-spacing:.14em; color:#8495b7; padding:24px 26px 10px; }
    .side-links { padding:0 12px; }
    .side-links a { display:flex; align-items:center; gap:12px; text-decoration:none; color:#c2cee3; padding:12px 14px; margin:4px 0; border-radius:11px; font-size:14px; font-weight:550; transition:background .15s; }
    .side-links a:hover, .side-links a.active { background:rgba(108,116,242,.20); color:#fff; }
    .nav-dot { width:24px; text-align:center; font-size:17px; }
    .sidebar-foot { margin-top:auto; padding:20px 22px; font-size:11px; color:#98a8c3; border-top:1px solid rgba(255,255,255,.09); }
    .main-area { margin-left:252px; min-height:100vh; }
    .topbar { background:#fff; border-bottom:1px solid var(--line); min-height:72px; display:flex; align-items:center; justify-content:space-between; padding:13px 34px; gap:12px; }
    .page-content { max-width:1390px; margin:auto; padding:28px 34px 55px; }
    .eyebrow { text-transform:uppercase; letter-spacing:.12em; color:#77849b; font-size:11px; font-weight:750; }
    .heading { font-size:25px; letter-spacing:-.025em; font-weight:780; margin:4px 0; }
    .muted { color:#718097; }
    .pill { font-size:12px; color:#137f56; background:#e9f8f0; border:1px solid #d0efdd; border-radius:24px; font-weight:700; padding:7px 12px; white-space:nowrap; }
    .hero { background:linear-gradient(115deg,#3431a7 0%,#4f46e5 60%,#7388f5 100%); color:#fff; border-radius:18px; padding:26px 30px; display:flex; justify-content:space-between; align-items:center; gap:20px; box-shadow:0 12px 32px rgba(70,67,188,.14); }
    .hero h1 { font-size:24px; font-weight:780; line-height:1.2; letter-spacing:-.02em; margin:4px 0 7px; }
    .hero p { color:#e2e7ff; font-size:13px; margin:0; max-width:520px; }
    .hero .btn { white-space:nowrap; font-weight:700; }
    .stat-card { background:white; padding:20px 22px; height:100%; border-radius:15px; border:1px solid var(--line); box-shadow:0 4px 18px rgba(33,50,86,.04); position:relative; overflow:hidden; }
    .stat-label { color:#6e7c93; font-size:12px; font-weight:650; margin-bottom:8px; }
    .stat-value { font-size:30px; font-weight:800; letter-spacing:-.04em; line-height:1.15; }
    .stat-note { color:#9aa6b8; font-size:11px; margin-top:7px; }
    .stat-icon { position:absolute; right:16px; top:15px; border-radius:11px; background:#f0efff; color:#5148db; width:36px; height:36px; display:grid; place-items:center; font-size:17px; }
    .stat-green .stat-icon { background:#e5f8ed; color:#178557; }
    .stat-red .stat-icon { background:#ffedf0; color:#d63c5c; }
    .stat-blue .stat-icon { background:#eaf3ff; color:#2166ca; }
    .section-card { background:#fff; border:1px solid var(--line); border-radius:16px; box-shadow:0 5px 19px rgba(33,50,86,.04); overflow:hidden; }
    .section-head { display:flex; align-items:center; justify-content:space-between; gap:12px; padding:20px 22px; border-bottom:1px solid var(--line); flex-wrap:wrap; }
    .section-title { margin:0; font-size:16px; font-weight:770; }
    .section-sub { color:#8290a4; font-size:12px; margin:5px 0 0; }
    .section-body { padding:22px; }
    .form-label { color:#4a5871; font-size:12px; font-weight:680; }
    .form-control, .form-select { border-color:#dfe5f0; border-radius:9px; padding:10px 12px; font-size:13px; }
    .form-control:focus,.form-select:focus { border-color:#8686ef; box-shadow:0 0 0 .2rem rgba(79,70,229,.10); }
    .btn { border-radius:9px; font-size:13px; font-weight:670; padding:9px 15px; }
    .btn-primary { background:#4f46e5; border-color:#4f46e5; }
    .btn-primary:hover { background:#423ac8; border-color:#423ac8; }
    .btn-outline-primary { color:#4f46e5; border-color:#c8c6ff; }
    .btn-outline-primary:hover { color:white; background:#4f46e5; border-color:#4f46e5; }
    .btn-soft { background:#edf0fa; border:1px solid #e3e8f4; color:#44516a; }
    .btn-soft:hover { background:#e1e7f7; color:#303c50; }
    .form-preview { display:flex; justify-content:space-between; align-items:center; gap:16px; padding:15px 17px; border:1px dashed #bfc9f3; border-radius:12px; background:#f8f9ff; margin-top:19px; }
    .preview-grade { font-size:23px; font-weight:780; color:#3531a7; }
    .badge-pass { background:#e3f7eb; color:#117a4d; border:1px solid #cbebd8; }
    .badge-fail { background:#ffeaf0; color:#bb274a; border:1px solid #ffd3df; }
    .grade-badge { display:inline-block; padding:6px 9px; font-size:11px; border-radius:7px; font-weight:800; letter-spacing:.025em; }
    .filter-bar { display:flex; flex-wrap:wrap; gap:10px; align-items:center; padding:18px 22px; border-bottom:1px solid var(--line); }
    .filter-bar .search { flex:1 1 270px; }
    .filter-bar .form-select { max-width:170px; flex:1 1 125px; }
    .table { margin:0; font-size:12px; white-space:nowrap; }
    .table thead th { background:#f8f9fc; color:#69778f; font-size:11px; text-transform:uppercase; letter-spacing:.04em; font-weight:800; border-bottom:1px solid #e6eaf2; padding:14px 12px; }
    .table tbody td { padding:13px 12px; vertical-align:middle; border-color:#eef1f7; }
    .table tbody tr:hover { background:#f9faff; }
    .name-strong { color:#26344c; font-weight:730; }
    .record-actions { display:flex; gap:5px; }
    .record-actions .btn { padding:6px 10px; font-size:11px; }
    .insight-row { display:flex; align-items:center; gap:11px; margin-bottom:15px; }
    .insight-row:last-child { margin:0; }
    .subject-name { min-width:90px; max-width:135px; width:32%; text-overflow:ellipsis; overflow:hidden; white-space:nowrap; font-size:12px; font-weight:700; }
    .progress { background:#e9ecf7; border-radius:100px; height:10px; flex:1; }
    .progress-bar { background:#5b54df; }
    .help-note { font-size:12px; color:#7e8aa0; }
    .print-header { display:none; }
    @media(max-width:991px) { .side-nav { position:relative; width:auto; } .brand { padding:14px 18px; border:0; } .nav-caption,.sidebar-foot { display:none; } .side-links { display:flex; gap:5px; overflow:auto; padding:0 12px 10px; } .side-links a { white-space:nowrap; padding:8px 12px; } .main-area { margin-left:0; } .topbar { padding:15px 18px; min-height:60px; } .page-content { padding:20px 16px 35px; } }
    @media(max-width:580px) { .hero { align-items:flex-start; flex-direction:column; padding:22px; } .hero h1 { font-size:22px; } .stat-card { padding:16px; } .section-body { padding:16px; } .section-head { padding:16px; } .filter-bar { padding:15px; } .filter-bar .form-select { max-width:none; } .topbar .top-label { display:none; } }
    @media print { @page { size:landscape; margin:13mm; } body { background:#fff; } .side-nav,.topbar,.hero,.stat-grid,#student-form,#insights,.filter-bar,.record-actions,.no-print,.alert { display:none !important; } .main-area { margin:0; } .page-content { max-width:100%; padding:0; } #records { border:0; box-shadow:none; } .section-head { padding:8px 0; } .section-sub { display:none; } .print-header { display:block; margin-bottom:18px; } .table { font-size:10px; } .table thead th { background:#eef1f9 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; } .table tbody td,.table thead th { padding:8px 6px; } .print-hide { display:none !important; } }
  </style>
</head>
<body>
  <aside class="side-nav">
    <div class="brand"><div class="brand-icon">SG</div><div><div class="brand-title">Student Grading<br>System</div><div class="brand-sub">Academic workspace</div></div></div>
    <div class="nav-caption">MAIN MENU</div>
    <nav class="side-links" aria-label="Main navigation">
      <a class="active" href="#overview"><span class="nav-dot">▦</span> Overview</a>
      <a href="#student-form"><span class="nav-dot">＋</span> <?php echo $editing ? 'Edit Student' : 'Add Student'; ?></a>
      <a href="#records"><span class="nav-dot">▤</span> Student Records</a>
      <a href="#insights"><span class="nav-dot">◫</span> Subject Insights</a>
    </nav>
    <div class="sidebar-foot">PHP + MySQL + Bootstrap 5<br>Admin-protected dashboard</div>
  </aside>

  <div class="main-area">
    <header class="topbar">
      <div><div class="eyebrow">Academic Management</div><strong class="top-label" style="font-size:14px">Grading Dashboard</strong></div>
      <div class="d-flex align-items-center gap-2">
        <span class="pill d-none d-sm-inline-flex">● Database Connected</span>
        <span class="small text-muted d-none d-md-inline">Admin: <?php echo clean($_SESSION['admin_username'] ?? ''); ?></span>
        <form method="post" action="logout.php" class="m-0 no-print">
          <input type="hidden" name="csrf_token" value="<?php echo clean($_SESSION['csrf_token']); ?>">
          <button class="btn btn-sm btn-outline-secondary" type="submit">Log out</button>
        </form>
      </div>
    </header>
    <main class="page-content">
      <div class="print-header"><h2>Student Grading System — Grade Report</h2><p>Printed on <?php echo clean(date('F j, Y')); ?></p></div>
      <section id="overview">
        <div class="hero mb-4">
          <div><div class="eyebrow" style="color:#c9d1ff">STUDENT MANAGEMENT PORTAL</div><h1>Manage student grades with ease.</h1><p>View student performance, update grade records, and generate a clean report—all in one place.</p></div>
          <a class="btn btn-light text-primary" href="#student-form">＋ Add Student</a>
        </div>
        <div class="row g-3 mb-4 stat-grid">
          <div class="col-6 col-xl-3"><div class="stat-card stat-blue"><div class="stat-icon">▤</div><div class="stat-label">Total Grade Records</div><div class="stat-value text-primary"><?php echo $total; ?></div><div class="stat-note">Records in database</div></div></div>
          <div class="col-6 col-xl-3"><div class="stat-card stat-green"><div class="stat-icon">✓</div><div class="stat-label">Passed</div><div class="stat-value" style="color:#169664"><?php echo $passed; ?></div><div class="stat-note"><?php echo $passRate; ?>% passing rate</div></div></div>
          <div class="col-6 col-xl-3"><div class="stat-card stat-red"><div class="stat-icon">!</div><div class="stat-label">Failed</div><div class="stat-value" style="color:#e54860"><?php echo $failed; ?></div><div class="stat-note">Grade average above 3.00</div></div></div>
          <div class="col-6 col-xl-3"><div class="stat-card"><div class="stat-icon">◈</div><div class="stat-label">Subjects</div><div class="stat-value"><?php echo $subjectCount; ?></div><div class="stat-note">Distinct subject names</div></div></div>
        </div>
      </section>

      <?php if (isset($successMessages[$status])): ?>
        <div class="alert alert-success no-print" role="status"><?php echo clean($successMessages[$status]); ?></div>
      <?php endif; ?>
      <?php if ($error !== ''): ?>
        <div class="alert alert-danger no-print" role="alert"><?php echo clean($error); ?></div>
      <?php endif; ?>

      <section id="student-form" class="section-card mb-4">
        <div class="section-head"><div><h2 class="section-title"><?php echo $editing ? '✎ Edit Student Record' : '＋ Add New Student'; ?></h2><p class="section-sub">Fill in the details below. Grades use the 1.00–5.00 scale.</p></div><span class="badge rounded-pill text-bg-light border"><?php echo $editing ? 'UPDATE MODE' : 'NEW RECORD'; ?></span></div>
        <div class="section-body">
          <form method="POST" action="index.php#student-form">
            <input type="hidden" name="csrf_token" value="<?php echo clean($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="action" value="save">
            <input type="hidden" name="id" value="<?php echo $editing ? $editId : 0; ?>">
            <div class="row g-3">
              <div class="col-md-6"><label class="form-label" for="student_number">Student Number</label><input class="form-control" id="student_number" name="student_number" maxlength="20" placeholder="e.g. 2026-001" value="<?php echo clean($form['student_number']); ?>" required></div>
              <div class="col-md-6"><label class="form-label" for="full_name">Full Name</label><input class="form-control" id="full_name" name="full_name" maxlength="100" placeholder="Enter student's full name" value="<?php echo clean($form['full_name']); ?>" required></div>
              <div class="col-12"><label class="form-label" for="subject">Subject</label><input class="form-control" id="subject" name="subject" maxlength="100" placeholder="e.g. Web Systems Technologies" value="<?php echo clean($form['subject']); ?>" required></div>
              <div class="col-md-4"><label class="form-label" for="prelim">Prelim Grade</label><input type="number" class="form-control grade-input" id="prelim" name="prelim" min="1" max="5" step="0.01" placeholder="1.00–5.00" value="<?php echo clean($form['prelim']); ?>" required></div>
              <div class="col-md-4"><label class="form-label" for="midterm">Midterm Grade</label><input type="number" class="form-control grade-input" id="midterm" name="midterm" min="1" max="5" step="0.01" placeholder="1.00–5.00" value="<?php echo clean($form['midterm']); ?>" required></div>
              <div class="col-md-4"><label class="form-label" for="final_exam">Final Grade</label><input type="number" class="form-control grade-input" id="final_exam" name="final_exam" min="1" max="5" step="0.01" placeholder="1.00–5.00" value="<?php echo clean($form['final_exam']); ?>" required></div>
            </div>
            <div class="form-preview" aria-live="polite"><div><div class="form-label mb-1">Live Average Preview</div><div class="help-note">Equal-weight average of prelim, midterm and final.</div></div><div class="text-end"><div class="preview-grade" id="live-average">—</div><span class="grade-badge" id="live-remark" style="background:#e9ecf5;color:#64748b">Enter grades</span></div></div>
            <div class="d-flex flex-wrap gap-2 mt-3"><button type="submit" class="btn btn-primary"><?php echo $editing ? '✓ Update Student' : '＋ Save Student'; ?></button><?php if ($editing): ?><a href="index.php#student-form" class="btn btn-soft">Cancel Edit</a><?php endif; ?></div>
          </form>
        </div>
      </section>

      <section class="section-card mb-4" id="records">
        <div class="section-head"><div><h2 class="section-title">Student Grade Records</h2><p class="section-sub">Search, filter, edit, or print your records.</p></div><div class="d-flex flex-wrap gap-2 no-print"><a class="btn btn-outline-primary" href="index.php?export=csv">↓ Export CSV</a><button class="btn btn-soft" type="button" onclick="window.print()">⎙ Print Report</button></div></div>
        <div class="filter-bar no-print"><input id="record-search" type="search" class="form-control search" placeholder="Search student number, name, or subject..." aria-label="Search student records"><select id="remark-filter" class="form-select" aria-label="Filter by remarks"><option value="all">All Remarks</option><option value="PASSED">Passed</option><option value="FAILED">Failed</option></select><select id="subject-filter" class="form-select" aria-label="Filter by subject"><option value="all">All Subjects</option><?php foreach (array_keys($subjectStats) as $subjectName): ?><option value="<?php echo clean($subjectName); ?>"><?php echo clean($subjectName); ?></option><?php endforeach; ?></select></div>
        <div class="table-responsive">
          <table class="table align-middle" id="records-table"><thead><tr><th>Student No.</th><th>Student Name</th><th>Subject</th><th>Prelim</th><th>Midterm</th><th>Final</th><th>Average</th><th>Remarks</th><th class="print-hide">Actions</th></tr></thead><tbody>
          <?php if ($total === 0): ?><tr id="no-records"><td colspan="9" class="text-center py-5 text-muted">No student records yet. Use the form above to add one.</td></tr><?php endif; ?>
          <?php foreach ($students as $row): ?>
            <?php $average = ((float)$row['prelim'] + (float)$row['midterm'] + (float)$row['final_exam']) / 3; $isPassed = $average <= 3.00; ?>
            <tr class="student-row" data-search="<?php echo clean(strtolower($row['student_number'] . ' ' . $row['full_name'] . ' ' . $row['subject'])); ?>" data-remark="<?php echo $isPassed ? 'PASSED' : 'FAILED'; ?>" data-subject="<?php echo clean($row['subject']); ?>">
              <td><strong><?php echo clean($row['student_number']); ?></strong></td><td class="name-strong"><?php echo clean($row['full_name']); ?></td><td><?php echo clean($row['subject']); ?></td>
              <td><?php echo number_format((float)$row['prelim'], 2); ?></td><td><?php echo number_format((float)$row['midterm'], 2); ?></td><td><?php echo number_format((float)$row['final_exam'], 2); ?></td><td class="fw-bold"><?php echo number_format($average, 2); ?></td><td><span class="grade-badge <?php echo $isPassed ? 'badge-pass' : 'badge-fail'; ?>"><?php echo $isPassed ? 'PASSED' : 'FAILED'; ?></span></td>
              <td class="print-hide"><div class="record-actions"><a class="btn btn-sm btn-outline-primary" href="index.php?edit=<?php echo (int)$row['id']; ?>#student-form">Edit</a><form method="POST" action="index.php" onsubmit="return confirm('Delete this student record? This cannot be undone.');"><input type="hidden" name="csrf_token" value="<?php echo clean($_SESSION['csrf_token']); ?>"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>"><button class="btn btn-sm btn-outline-danger" type="submit">Delete</button></form></div></td>
            </tr>
          <?php endforeach; ?>
          <tr id="no-results" style="display:none"><td colspan="9" class="text-center py-4 text-muted">No records match your search or filters.</td></tr>
          </tbody></table>
        </div>
        <div class="d-flex flex-wrap justify-content-between gap-2 px-4 py-3 border-top no-print"><div class="small muted" id="showing-count">Showing <?php echo $total; ?> of <?php echo $total; ?> records</div><div class="small muted">Grading demo: 1.00–3.00 Passed · Above 3.00 Failed</div></div>
      </section>

      <section id="insights" class="row g-3">
        <div class="col-lg-7"><div class="section-card h-100"><div class="section-head"><div><h2 class="section-title">Performance by Subject</h2><p class="section-sub">Passing percentage from the saved grade records.</p></div></div><div class="section-body">
          <?php if (!$subjectStats): ?><p class="help-note mb-0">Subject analytics will appear after you add a record.</p><?php endif; ?>
          <?php foreach ($subjectStats as $name => $stat): ?>
            <?php $rate = round(100 * $stat['passed'] / $stat['count']); ?>
            <div class="insight-row"><div class="subject-name" title="<?php echo clean($name); ?>"><?php echo clean($name); ?></div><div class="progress" role="progressbar" aria-label="Passing percentage for <?php echo clean($name); ?>" aria-valuenow="<?php echo $rate; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?php echo $rate; ?>%"></div></div><div class="small fw-bold" style="min-width:62px;text-align:right"><?php echo $rate; ?>% <span class="muted fw-normal">(<?php echo $stat['count']; ?>)</span></div></div>
          <?php endforeach; ?>
        </div></div></div>
        <div class="col-lg-5"><div class="section-card h-100"><div class="section-head"><div><h2 class="section-title">Grading Overview</h2><p class="section-sub">Automatic computation summary.</p></div></div><div class="section-body"><div class="d-flex justify-content-between align-items-center mb-3"><span class="muted small">Overall average grade</span><strong class="fs-4"><?php echo $overallAverage === null ? '—' : number_format($overallAverage, 2); ?></strong></div><div class="d-flex justify-content-between align-items-center mb-3"><span class="muted small">Passing rate</span><strong class="fs-5"><?php echo $passRate; ?>%</strong></div><div class="progress mb-3" role="progressbar" aria-label="Overall passing rate" aria-valuenow="<?php echo $passRate; ?>" aria-valuemin="0" aria-valuemax="100"><div class="progress-bar" style="width:<?php echo $passRate; ?>%"></div></div><p class="help-note mb-0">Grade = (Prelim + Midterm + Final) ÷ 3. A lower numeric grade represents better performance on this demo scale. Confirm your college's official passing policy.</p></div></div></div>
      </section>
      <footer class="small muted text-center mt-4">Student Grading System · PHP + MariaDB · Bootstrap 5 · Class Project</footer>
    </main>
  </div>
  <script>
    (function () {
      const fields = ['prelim','midterm','final_exam'].map(id => document.getElementById(id));
      const averageView = document.getElementById('live-average');
      const remarkView = document.getElementById('live-remark');
      function updatePreview() {
        const vals = fields.map(el => el.value.trim() === '' ? NaN : Number(el.value));
        if (vals.some(n => !Number.isFinite(n) || n < 1 || n > 5)) {
          averageView.textContent='—'; remarkView.textContent='Enter grades 1–5';
          remarkView.className='grade-badge'; remarkView.style.cssText='background:#e9ecf5;color:#64748b';
          return;
        }
        const average = vals.reduce((a,b)=>a+b,0)/3;
        const passed = average <= 3;
        averageView.textContent=average.toFixed(2);
        remarkView.textContent=passed?'PASSED':'FAILED';
        remarkView.className='grade-badge '+(passed?'badge-pass':'badge-fail');
        remarkView.style.cssText='';
      }
      fields.forEach(el => el.addEventListener('input',updatePreview));
      updatePreview();

      const search = document.getElementById('record-search');
      const remark = document.getElementById('remark-filter');
      const subject = document.getElementById('subject-filter');
      const rows = Array.from(document.querySelectorAll('.student-row'));
      const empty = document.getElementById('no-results');
      const counter = document.getElementById('showing-count');
      function filterRecords() {
        const q = search.value.toLocaleLowerCase().trim();
        let shown = 0;
        rows.forEach(row => {
          const match = row.dataset.search.includes(q)
            && (remark.value === 'all' || row.dataset.remark === remark.value)
            && (subject.value === 'all' || row.dataset.subject === subject.value);
          row.style.display = match ? '' : 'none';
          if (match) shown++;
        });
        empty.style.display = rows.length && !shown ? '' : 'none';
        counter.textContent = `Showing ${shown} of ${rows.length} records`;
      }
      [search,remark,subject].forEach(el => el.addEventListener(el === search ? 'input' : 'change',filterRecords));
    })();
  </script>
</body>
</html>
