<?php
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Employee HRIS API</title>
    <link rel="stylesheet"
        href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css">
</head>

<body>
    <div id="swagger-ui"></div>

    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
        SwaggerUIBundle({
            dom_id: '#swagger-ui',
            withCredentials: true,
            spec: {
                openapi: '3.0.3',
                info: {
                    title: 'Employee HRIS API',
                    version: '1.0.0',
                    description: 'Employee Human Resource Information System API'
                },
                servers: [{
                    url: window.location.origin
                }],
                components: {
                    securitySchemes: {
                        sessionCookie: {
                            type: 'apiKey',
                            in: 'cookie',
                            name: 'PHPSESSID'
                        }
                    }
                },
                paths: {
                    '/api/employees.php': {
                        get: {
                            tags: ['Employees'],
                            summary: 'List employees',
                            description: 'Requires an authenticated admin or HR session.',
                            security: [{
                                sessionCookie: []
                            }],
                            parameters: [{
                                    name: 'q',
                                    in: 'query',
                                    description: 'Search by employee ID, name, or email',
                                    schema: {
                                        type: 'string'
                                    }
                                },
                                {
                                    name: 'sort_by',
                                    in: 'query',
                                    schema: {
                                        type: 'string',
                                        enum: [
                                            'employee_id',
                                            'name',
                                            'department_name',
                                            'position_title',
                                            'date_hired',
                                            'employment_status'
                                        ]
                                    }
                                },
                                {
                                    name: 'dir',
                                    in: 'query',
                                    schema: {
                                        type: 'string',
                                        enum: ['asc', 'desc'],
                                        default: 'asc'
                                    }
                                }
                            ],
                            responses: {
                                '200': {
                                    description: 'Employees retrieved successfully'
                                },
                                '400': {
                                    description: 'Invalid query parameter'
                                },
                                '401': {
                                    description: 'Authentication required'
                                },
                                '403': {
                                    description: 'Insufficient permissions'
                                },
                                '500': {
                                    description: 'Internal server error'
                                }
                            }
                        }
                    },
                    '/api/employee.php': {
                        get: {
                            tags: ['Employees'],
                            summary: 'Get employee by database ID',
                            security: [{
                                sessionCookie: []
                            }],
                            parameters: [{
                                name: 'id',
                                in: 'query',
                                required: true,
                                schema: {
                                    type: 'integer',
                                    minimum: 1
                                }
                            }],
                            responses: {
                                '200': {
                                    description: 'Employee retrieved successfully'
                                },
                                '400': {
                                    description: 'Invalid employee ID'
                                },
                                '401': {
                                    description: 'Authentication required'
                                },
                                '403': {
                                    description: 'Insufficient permissions'
                                },
                                '404': {
                                    description: 'Employee not found'
                                },
                                '500': {
                                    description: 'Internal server error'
                                }
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>

</html>