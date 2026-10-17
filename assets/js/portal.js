/* EduResult staff portal — small progressive enhancements, no libraries. */
(function () {
  // Ask before destructive actions: <form data-confirm="Delete this?">
  document.addEventListener('submit', function (e) {
    var msg = e.target.getAttribute('data-confirm');
    if (msg && !window.confirm(msg)) { e.preventDefault(); }
  });

  // Live filter for tables: <input data-filter="#tableId">
  document.querySelectorAll('[data-filter]').forEach(function (input) {
    var rows = document.querySelectorAll(input.getAttribute('data-filter') + ' tbody tr');
    input.addEventListener('input', function () {
      var q = input.value.toLowerCase();
      rows.forEach(function (r) { r.style.display = r.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none'; });
    });
  });

  // Auto-submit filter selects: <select data-autosubmit>
  document.querySelectorAll('select[data-autosubmit]').forEach(function (s) {
    s.addEventListener('change', function () { s.form.submit(); });
  });

  // Score entry grid: live totals and grades, and bounds checking.
  var grid = document.querySelector('table.scores');
  if (grid) {
    var scale = JSON.parse(grid.getAttribute('data-scale'));
    var gradeOf = function (t) {
      for (var i = 0; i < scale.length; i++) { if (t >= scale[i][0]) return scale[i][1]; }
      return '';
    };
    var update = function (row) {
      var total = 0, any = false, ok = true;
      row.querySelectorAll('input[data-max]').forEach(function (inp) {
        var v = inp.value.trim();
        var max = parseFloat(inp.getAttribute('data-max'));
        var bad = v !== '' && (isNaN(parseFloat(v)) || parseFloat(v) < 0 || parseFloat(v) > max);
        inp.classList.toggle('bad', bad);
        if (bad) ok = false;
        if (v !== '' && !bad) { total += parseFloat(v); any = true; }
      });
      row.querySelector('.total').textContent = any ? (Math.round(total * 100) / 100) : '';
      row.querySelector('.grade').textContent = any ? gradeOf(total) : '';
      return ok;
    };
    grid.querySelectorAll('tbody tr').forEach(function (row) {
      update(row);
      row.addEventListener('input', function () { update(row); });
    });
    // Enter moves down the column like a spreadsheet.
    grid.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter' || e.target.tagName !== 'INPUT') return;
      e.preventDefault();
      var td = e.target.closest('td');
      var idx = Array.prototype.indexOf.call(td.parentNode.children, td);
      var next = td.parentNode.nextElementSibling;
      if (next) { var inp = next.children[idx].querySelector('input'); if (inp) inp.focus(); }
    });
    grid.closest('form').addEventListener('submit', function (e) {
      var ok = true;
      grid.querySelectorAll('tbody tr').forEach(function (row) { if (!update(row)) ok = false; });
      if (!ok) { e.preventDefault(); alert('Some scores are above the maximum or not numbers. They are highlighted in red.'); }
    });
  }

  // Select-all checkboxes: <input type=checkbox data-check-all=".row-check">
  document.querySelectorAll('[data-check-all]').forEach(function (box) {
    box.addEventListener('change', function () {
      document.querySelectorAll(box.getAttribute('data-check-all')).forEach(function (c) { c.checked = box.checked; });
    });
  });
})();
