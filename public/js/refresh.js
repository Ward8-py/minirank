(function () {
  'use strict';

  var button = document.getElementById('refresh-positions');
  if (!button) {
    return;
  }

  var tokenInput = document.getElementById('refresh-csrf');
  var status = document.getElementById('refresh-status');
  var results = document.getElementById('results');
  var searchInput = document.getElementById('q');
  var movementSelect = document.getElementById('movement');
  var busy = false;

  var GENERIC_MESSAGE = 'Could not refresh. Please try again.';
  var EMPTY_MESSAGE = 'No keywords match your search.';

  function setStatus(message) {
    if (status) {
      status.textContent = message;
    }
  }

  function trendLabel(keyword) {
    var label = String(keyword.direction);
    if (keyword.not_enough_history) {
      label = label + ' (not enough history)';
    }
    return label;
  }

  function buildRow(keyword) {
    var row = document.createElement('tr');
    row.setAttribute('data-keyword-id', String(keyword.id));

    var phraseCell = document.createElement('td');
    var link = document.createElement('a');
    link.href = 'keyword.php?id=' + String(keyword.id);
    link.textContent = String(keyword.phrase);
    phraseCell.appendChild(link);
    row.appendChild(phraseCell);

    var positionCell = document.createElement('td');
    positionCell.className = 'position';
    positionCell.textContent = keyword.current_position === null
      ? '\u2014'
      : String(keyword.current_position);
    row.appendChild(positionCell);

    var trendCell = document.createElement('td');
    trendCell.className = 'trend trend-' + String(keyword.direction);
    trendCell.textContent = trendLabel(keyword);
    row.appendChild(trendCell);

    return row;
  }

  function renderResults(keywords) {
    if (!results) {
      return;
    }
    results.textContent = '';
    if (!Array.isArray(keywords) || keywords.length === 0) {
      var empty = document.createElement('p');
      empty.className = 'no-results';
      empty.textContent = EMPTY_MESSAGE;
      results.appendChild(empty);
      return;
    }
    var table = document.createElement('table');
    table.className = 'keywords';
    var thead = document.createElement('thead');
    var headRow = document.createElement('tr');
    ['Keyword', 'Current position', '7-day trend'].forEach(function (heading) {
      var th = document.createElement('th');
      th.textContent = heading;
      headRow.appendChild(th);
    });
    thead.appendChild(headRow);
    table.appendChild(thead);
    var tbody = document.createElement('tbody');
    keywords.forEach(function (keyword) {
      tbody.appendChild(buildRow(keyword));
    });
    table.appendChild(tbody);
    results.appendChild(table);
  }

  button.addEventListener('click', function () {
    if (busy) {
      return;
    }
    busy = true;
    button.disabled = true;
    setStatus('Refreshing\u2026');

    var token = tokenInput ? tokenInput.value : '';
    var params = new URLSearchParams({ csrf_token: token });
    if (searchInput) {
      params.set('q', searchInput.value);
    }
    if (movementSelect) {
      params.set('movement', movementSelect.value);
    }

    fetch('actions/refresh.php', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/x-www-form-urlencoded',
        'Accept': 'application/json'
      },
      body: params
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
        renderResults(result.data.keywords);
        setStatus('Updated ' + String(result.data.refreshed) + ' keywords.');
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