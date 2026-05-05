<?php
// test_singleton.php
// open this in browser: http://localhost/cms/test_singleton.php
// shows proof that Database::getInstance() returns the same
// object every single time no matter how many times you call it

require_once 'backend/patterns/Database.php';

// call getInstance() three times separately
$instance1 = Database::getInstance();
$instance2 = Database::getInstance();
$instance3 = Database::getInstance();

// === for objects means same object in memory, not just equal values
// should be true both times since they all point to the same object
$test1 = ($instance1 === $instance2);
$test2 = ($instance2 === $instance3);

// spl_object_id() gives the internal ID php assigned to the object
// if all three IDs are the same number, no second object was ever created
$id1 = spl_object_id($instance1);
$id2 = spl_object_id($instance2);
$id3 = spl_object_id($instance3);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Singleton - Proof</title>
<style>
  body { font-family: Arial, sans-serif; padding: 32px; background: #f5f5f5; }
  h2   { color: #1e2d4a; margin-bottom: 4px; }
  .subtitle { color: #666; font-size: 0.88rem; margin-bottom: 28px; }
  .card {
    background: #fff;
    border: 1px solid #dde;
    border-radius: 5px;
    padding: 22px 24px;
    margin-bottom: 18px;
    max-width: 680px;
  }
  .card h3 { font-size: 0.95rem; color: #333; margin-bottom: 14px; }
  table { border-collapse: collapse; width: 100%; font-size: 0.88rem; }
  th { background: #f5f6fa; text-align: left; padding: 8px 12px; border-bottom: 2px solid #dde; }
  td { padding: 9px 12px; border-bottom: 1px solid #eef; }
  tr:last-child td { border-bottom: none; }
  .pass { color: #2e7d32; font-weight: bold; }
  .fail { color: #b71c1c; font-weight: bold; }
  pre {
    background: #f5f6fa;
    border: 1px solid #dde;
    border-radius: 4px;
    padding: 14px 16px;
    font-size: 0.83rem;
    line-height: 1.7;
    margin: 0;
  }
  .explanation {
    font-size: 0.85rem;
    color: #444;
    line-height: 1.6;
    margin-top: 12px;
  }
</style>
</head>
<body>

<h2>Singleton Pattern - Proof</h2>
<p class="subtitle">
  calling Database::getInstance() 3 times - all should return the same object
</p>

<!-- Object ID comparison -->
<div class="card">
  <h3>Object IDs</h3>
  <p class="explanation" style="margin-top:0; margin-bottom:14px;">
    php gives every object a unique number when it is created.
    if all three show the same number, no second object was made.
  </p>
  <table>
    <thead>
      <tr><th>Variable</th><th>Object ID</th><th>Result</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>$instance1 = Database::getInstance()</td>
        <td><?= $id1 ?></td>
        <td class="pass">first call - object created and stored</td>
      </tr>
      <tr>
        <td>$instance2 = Database::getInstance()</td>
        <td><?= $id2 ?></td>
        <td class="<?= $id1 === $id2 ? 'pass' : 'fail' ?>">
          <?= $id1 === $id2 ? 'same ID - returned the stored object, no new one created' : 'different object - singleton not working' ?>
        </td>
      </tr>
      <tr>
        <td>$instance3 = Database::getInstance()</td>
        <td><?= $id3 ?></td>
        <td class="<?= $id1 === $id3 ? 'pass' : 'fail' ?>">
          <?= $id1 === $id3 ? 'same ID - returned the stored object, no new one created' : 'different object - singleton not working' ?>
        </td>
      </tr>
    </tbody>
  </table>
</div>

<!-- Strict equality check -->
<div class="card">
  <h3>Strict Equality (===)</h3>
  <p class="explanation" style="margin-top:0; margin-bottom:14px;">
    === for objects is true only if both variables point to literally
    the same object in memory, not just objects with same values.
  </p>
  <table>
    <thead>
      <tr><th>Comparison</th><th>Result</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>$instance1 === $instance2</td>
        <td class="<?= $test1 ? 'pass' : 'fail' ?>">
          <?= $test1 ? 'true - same object' : 'FAIL' ?>
        </td>
      </tr>
      <tr>
        <td>$instance2 === $instance3</td>
        <td class="<?= $test2 ? 'pass' : 'fail' ?>">
          <?= $test2 ? 'true - same object' : 'false - FAIL' ?>
        </td>
      </tr>
    </tbody>
  </table>
</div>

<!-- What would happen without Singleton -->
<div class="card">
  <h3>What if you try new Database() directly?</h3>
  <pre>
$db = new Database();

// PHP crashes with:
// Fatal error: Call to private Database::__construct()

// the constructor is private so new Database() is blocked
// getInstance() is the only way to get the object
  </pre>
  <p class="explanation">
    the constructor in Database.php is <strong>private</strong>.
    nothing outside the class can call <code>new Database()</code>.
    the only way in is <code>Database::getInstance()</code>.
  </p>
</div>

<!-- The code that proves it -->
<div class="card">
  <h3>The code that runs this page</h3>
  <pre>
$instance1 = Database::getInstance();
$instance2 = Database::getInstance();
$instance3 = Database::getInstance();

// Strict comparison - true only if same object in memory
var_dump($instance1 === $instance2); // bool(true)
var_dump($instance2 === $instance3); // bool(true)

// Internal PHP object ID - same ID = same object
echo spl_object_id($instance1); // e.g. 1
echo spl_object_id($instance2); // e.g. 1  <- identical
echo spl_object_id($instance3); // e.g. 1  <- identical
  </pre>
</div>

<!-- Singleton structure reminder -->
<div class="card">
  <h3>The three things that make it Singleton</h3>
  <table>
    <thead><tr><th>Rule</th><th>How it is enforced</th></tr></thead>
    <tbody>
      <tr>
        <td>Only one object can ever exist</td>
        <td>private constructor blocks <code>new Database()</code> from outside</td>
      </tr>
      <tr>
        <td>Same object returned on every call</td>
        <td><code>static $instance</code> stores it; getInstance() returns same one if not null</td>
      </tr>
      <tr>
        <td>Cannot be cloned</td>
        <td>private <code>__clone()</code> blocks <code>clone $db</code></td>
      </tr>
    </tbody>
  </table>
</div>

</body>
</html>
