'use strict';

window.addEventListener('DOMContentLoaded', function () {
    if (typeof SwaggerUIBundle !== 'function') {
        document.getElementById('docs-error').hidden = false;
        return;
    }
    SwaggerUIBundle({
        url: '/api/openapi.json',
        dom_id: '#swagger-ui',
        deepLinking: false,
        validatorUrl: null,
        persistAuthorization: false,
        queryConfigEnabled: false,
        withCredentials: false,
        supportedSubmitMethods: ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'],
        presets: [SwaggerUIBundle.presets.apis],
        requestInterceptor: function (request) {
            const url = new URL(request.url, window.location.origin);
            if (url.origin !== window.location.origin || !url.pathname.startsWith('/api/')) {
                throw new Error('Only same-origin API requests are allowed.');
            }
            request.credentials = 'omit';
            return request;
        },
        onFailure: function () {
            document.getElementById('docs-error').hidden = false;
        }
    });
});
