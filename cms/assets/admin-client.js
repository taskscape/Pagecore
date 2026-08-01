(function (global) {
  'use strict';

  function create(config) {
    config = config || {};

    function decode(response) {
      var contentType = response.headers.get('content-type') || '';
      if (contentType.indexOf('application/json') === -1) {
        throw new Error(response.status === 401 ? 'Your session expired. Sign in again.' : 'The server returned an invalid response.');
      }
      return response.json().then(function (json) {
        if (!response.ok || !json || json.ok !== true) {
          var error = new Error(json && json.error ? json.error : 'Request failed.');
          error.status = response.status;
          throw error;
        }
        return json;
      });
    }

    function request(action, options) {
      options = options || {};
      var method = options.method || 'POST';
      var query = new URLSearchParams(options.query || {});
      query.set('action', action);
      var headers = { 'X-CMS-Token': config.token || '' };
      var body = options.form || null;
      if (!body && options.data) {
        body = new URLSearchParams();
        Object.keys(options.data).forEach(function (key) { body.append(key, options.data[key]); });
      }
      return fetch(config.api + '?' + query.toString(), { method: method, headers: headers, body: method === 'GET' ? null : body, signal: options.signal })
        .then(decode)
        .catch(function (error) {
          if (error.status === 401 && config.login) {
            global.location.href = config.login + '?next=' + encodeURIComponent(global.location.pathname + global.location.search + global.location.hash);
          }
          throw error;
        });
    }

    return {
      get: function (action, query) { return request(action, { method: 'GET', query: query }); },
      post: function (action, data, options) { return request(action, { data: data, signal: options && options.signal }); },
      upload: function (action, form) { return request(action, { form: form }); }
    };
  }

  global.PagecoreAdminClient = { create: create };
})(window);
