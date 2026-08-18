(function () {
  'use strict';

  var button = document.getElementById('refresh-positions');
  if (!button) {
    return;
  }

  var tokenInput = document.getElementById('refresh-csrf');
  var status = document.getElementById('refresh-status');
  var busy = false;

  var GENERIC_MESSAGE = 'Could not refresh. Please try again.';

  function setStatus(message) {
    if (status) {
      status.textContent = message;
    }
  }

  function updateTable(keywords) {
    keywords.forEach(function (keyword) {
      var row = document.querySelector(
        'tr[data-keyword-id="' + String(keyword.id) + '"]'
      );
      if (!row) {
        return;
      }
      var positionCell = row.querySelector('.position');
      if (positionCell) {
        positionCell.textContent = keyword.current_position === null
          ? '\u2014'
          : String(keyword.current_position);
      }
      var trendCell = row.querySelector('.trend');
      if (trendCell) {
        var direction = String(keyword.direction);
        if (direction === 'improved' || direction === 'declined' || direction === 'stable') {
          trendCell.className = 'trend trend-' + direction;
        }
        var label = direction;
        if (keyword.not_enough_history) {
          label = label + ' (not enough history)';
        }
        trendCell.textContent = label;
      }
    });
  }

  button.addEventListener('click', function () {
    if (busy) {
      return;
    }
    busy = true;
    button.disabled = true;
    setStatus('Refreshing\u2026');

    var token = tokenInput ? tokenInput.value : '';

    fetch('actions/refresh.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Accept': 'application/json'
      },
      body: new URLSearchParams({ csrf_token: token })
    })
      .then(function (response) {
        return response.json().then(function (data) {
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok || !result.data || result.data.ok !== true
            || !Array.isArray(result.data.keywords)) {
          var message = GENERIC_MESSAGE;
          if (result.data && typeof result.data.error === 'string') {
            message = result.data.error;
          }
          setStatus(message);
          return;
        }
        updateTable(result.data.keywords);
        setStatus('Updated ' + result.data.keywords.length + ' keywords.');
      })
      .catch(function () {
        setStatus(GENERIC_MESSAGE);
      })
      .then(function () {
        busy = false;
        button.disabled = false;
      });
  });
})();