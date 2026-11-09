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
        // Small builders to keep the spec below readable.
        const schema = (name) => ({ $ref: '#/components/schemas/' + name });
        const response = (name) => ({ $ref: '#/components/responses/' + name });

        const json = (body) => ({ 'application/json': { schema: body } });

        const jsonBody = (name, example) => ({
            required: true,
            content: { 'application/json': { schema: schema(name), example: example } }
        });

        // {"success": true, "message": "...", "data": ...}
        const ok = (description, data, extra = {}) => ({
            description: description,
            content: json({
                type: 'object',
                properties: Object.assign({
                    success: { type: 'boolean', example: true },
                    message: { type: 'string' }
                }, extra, { data: data })
            })
        });

        // {"success": true, "count": N, "data": [...]}
        const list = (description, item) => ({
            description: description,
            content: json({
                type: 'object',
                properties: {
                    success: { type: 'boolean', example: true },
                    count: { type: 'integer', example: 3 },
                    data: { type: 'array', items: schema(item) }
                }
            })
        });

        const idParam = {
            name: 'id',
            in: 'query',
            required: true,
            description: 'Employee database ID (`employees.id`)',
            schema: { type: 'integer', minimum: 1 },
            example: 1
        };

        const leaveStatusParam = {
            name: 'status',
            in: 'query',
            description: 'Only requests with this status. Omit to get all.',
            schema: { type: 'string', enum: ['Pending', 'Approved', 'Rejected', 'Cancelled'] }
        };

        const realtimeEvent = (type) => ({ type: 'string', example: type, description: 'Realtime event published to `employee:<id>` and `hr`' });

        const nullableString = (example, extra = {}) => Object.assign({ type: 'string', nullable: true, example: example }, extra);

        SwaggerUIBundle({
            dom_id: '#swagger-ui',
            withCredentials: true,
            deepLinking: true,
            displayRequestDuration: true,
            spec: {
                openapi: '3.0.3',
                info: {
                    title: 'Employee HRIS API',
                    version: '1.2.0',
                    description: [
                        'Employee Human Resource Information System API.',
                        '',
                        '**How to sign in:** the API uses the normal HRIS session cookie. ' +
                        'Sign in at [/authentication/login.php](/authentication/login.php) in this browser, ' +
                        'then come back here and use **Try it out**. To test another role, ' +
                        '[sign out](/authentication/logout.php) and sign in with that account.',
                        '',
                        '| Role | Access |',
                        '|---|---|',
                        '| `admin` | Full access to everything below, on everyone\'s data. |',
                        '| `hr` | Manage everyone\'s data. Employees: list, view, create, update, deactivate and reactivate. Leave: list every request, approve, reject. |',
                        '| `manager` | **Their own team only** (employees whose `manager_id` is the manager). Employees: list and view the team (read only). Leave: list the team\'s requests, approve or reject them. Anyone outside the team looks like it does not exist (404). |',
                        '| `employee` | Limited to their own data. Profile: view, and update personal details, photo and password (never the employee ID, status or job details). Leave: list, file and cancel their own requests. |',
                        '',
                        'These rules are not hard-coded: they come from the permission matrix in the database table `role_permissions` ' +
                        '(see **System Settings** at [/admin/permissions.php](/admin/permissions.php), admin only). ' +
                        'Changing a row in phpMyAdmin changes what the API allows, immediately.',
                        '',
                        '**Realtime:** leave actions publish WebSocket events through `realtime/server.js`: ' +
                        '`leave.filed`, `leave.cancelled`, `leave.approved` and `leave.rejected`. ' +
                        'Each event goes to the owner\'s channel `employee:<id>` and to `hr`, so employees only hear about their own requests. ' +
                        'Browsers get a connection token from `GET /api/realtime_token.php`.',
                        '',
                        'POST and PATCH requests must come from this site, and all of them except the photo upload ' +
                        'use `Content-Type: application/json`. A deactivated account is refused even if it still has an open session.'
                    ].join('\n')
                },
                servers: [{
                    url: window.location.origin
                }],
                tags: [{
                        name: 'HR & Admin: Employees',
                        description: 'Full employee management for `hr` and `admin`. A `manager` can read their own team only (writes return 403).'
                    },
                    {
                        name: 'HR & Admin: Leave requests',
                        description: 'Leave requests. `hr` and `admin` see and review everyone\'s; a `manager` sees and reviews their own team\'s only.'
                    },
                    {
                        name: 'Employee: My profile',
                        description: 'The signed-in employee\'s own record. Requires an `employee` session. Name, email and job details are managed by HR.'
                    },
                    {
                        name: 'Employee: My leave',
                        description: 'The signed-in employee\'s own leave requests. Requires an `employee` session.'
                    },
                    {
                        name: 'Realtime & shared',
                        description: 'Available to any signed-in account.'
                    }
                ],
                security: [{
                    sessionCookie: []
                }],
                paths: {
                    // ------------------------------------------------------------
                    // HR & Admin: Employees
                    // ------------------------------------------------------------
                    '/api/employees.php': {
                        get: {
                            tags: ['HR & Admin: Employees'],
                            summary: 'List employees',
                            description: '`hr` and `admin` get everyone. A `manager` gets only their own team. An `employee` gets 403.',
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
                                '200': list('Employees retrieved successfully', 'Employee'),
                                '400': response('BadRequest'),
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '500': response('ServerError')
                            }
                        },
                        post: {
                            tags: ['HR & Admin: Employees'],
                            summary: 'Create an employee',
                            description: 'The employee code (`EMP-###`) is generated. Omitted fields default like the HR "Add employee" form: ' +
                                '`employment_type` = Full-time, `employment_status` = Active. ' +
                                'Create the login account separately (admin: /admin/create_user.php).',
                            requestBody: jsonBody('EmployeeCreate', {
                                first_name: 'Maria',
                                middle_name: 'Santos',
                                last_name: 'Reyes',
                                date_of_birth: '1996-03-14',
                                gender: 'Female',
                                phone: '09171234567',
                                email: 'maria.reyes@example.com',
                                address: 'Cebu City, Cebu',
                                department_id: 2,
                                position_id: 2,
                                date_hired: '2026-11-06',
                                employment_type: 'Full-time',
                                employment_status: 'Active'
                            }),
                            responses: {
                                '201': Object.assign(ok('Employee created', schema('Employee')), {
                                    headers: {
                                        Location: { description: 'URL of the new employee', schema: { type: 'string', example: '/api/employee.php?id=4' } }
                                    }
                                }),
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '409': response('Conflict'),
                                '415': response('UnsupportedMediaType'),
                                '422': response('ValidationError'),
                                '500': response('ServerError')
                            }
                        }
                    },
                    '/api/employee.php': {
                        get: {
                            tags: ['HR & Admin: Employees'],
                            summary: 'Get employee by database ID',
                            description: '`hr` and `admin` can open any employee. A `manager` can open only someone on their team; anyone else returns 404, the same as an id that does not exist.',
                            parameters: [idParam],
                            responses: {
                                '200': ok('Employee retrieved successfully', schema('Employee')),
                                '400': response('BadRequest'),
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '404': response('NotFound'),
                                '500': response('ServerError')
                            }
                        },
                        patch: {
                            tags: ['HR & Admin: Employees'],
                            summary: 'Update an employee',
                            description: 'Send only the fields to change. Send `null` to clear an optional field. ' +
                                '`manager_id` sets who the employee reports to: it must be an employee with an **active manager account**, and not the employee themselves. ' +
                                'This edits the employee record only. To also disable or enable their login, use `POST /api/employee_status.php`.',
                            parameters: [idParam],
                            requestBody: jsonBody('EmployeeUpdate', {
                                phone: '09179876543',
                                position_id: 4,
                                employment_type: 'Full-time'
                            }),
                            responses: {
                                '200': ok('Employee updated', schema('Employee')),
                                '400': response('BadRequest'),
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '404': response('NotFound'),
                                '409': response('Conflict'),
                                '415': response('UnsupportedMediaType'),
                                '422': response('ValidationError'),
                                '500': response('ServerError')
                            }
                        }
                    },
                    '/api/employee_status.php': {
                        post: {
                            tags: ['HR & Admin: Employees'],
                            summary: 'Deactivate or reactivate an employee account',
                            description: '`Inactive` sets the employee to Inactive **and** disables every login linked to them, in one transaction. ' +
                                'They can no longer sign in, and any session they still have open is refused by the API. ' +
                                '`Active` reverses both. You cannot deactivate your own account.',
                            requestBody: jsonBody('EmployeeStatusChange', { id: 3, status: 'Inactive' }),
                            responses: {
                                '200': ok('Status changed', schema('EmployeeStatusResult')),
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '404': response('NotFound'),
                                '409': response('Conflict'),
                                '415': response('UnsupportedMediaType'),
                                '422': response('ValidationError'),
                                '500': response('ServerError')
                            }
                        }
                    },

                    // ------------------------------------------------------------
                    // HR & Admin: Leave requests
                    // ------------------------------------------------------------
                    '/api/leave_requests.php': {
                        get: {
                            tags: ['HR & Admin: Leave requests'],
                            summary: 'List leave requests',
                            description: 'Newest first. `hr` and `admin` get every request. A `manager` gets only their team\'s requests (asking for an `employee_id` outside the team returns an empty list).',
                            parameters: [leaveStatusParam, {
                                name: 'employee_id',
                                in: 'query',
                                description: 'Only this employee\'s requests (`employees.id`)',
                                schema: { type: 'integer', minimum: 1 }
                            }],
                            responses: {
                                '200': list('Leave requests retrieved', 'LeaveRequest'),
                                '400': response('BadRequest'),
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '500': response('ServerError')
                            }
                        }
                    },
                    '/api/leave_request_action.php': {
                        post: {
                            tags: ['HR & Admin: Leave requests'],
                            summary: 'Approve or reject a leave request',
                            description: 'Only Pending requests can be reviewed. Approving deducts the days from the employee\'s leave balance ' +
                                'and fails with 422 when the balance is too low. Publishes `leave.approved` or `leave.rejected` in realtime. ' +
                                'A `manager` can review only requests from their own team; any other request returns 404.',
                            requestBody: jsonBody('LeaveReview', { id: 1, action: 'approve' }),
                            responses: {
                                '200': ok('Request reviewed', schema('LeaveRequest'), {
                                    realtime_event: realtimeEvent('leave.approved')
                                }),
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '404': response('NotFound'),
                                '409': response('Conflict'),
                                '415': response('UnsupportedMediaType'),
                                '422': response('ValidationError'),
                                '500': response('ServerError')
                            }
                        }
                    },

                    // ------------------------------------------------------------
                    // Employee: My profile
                    // ------------------------------------------------------------
                    '/api/me/profile.php': {
                        get: {
                            tags: ['Employee: My profile'],
                            summary: 'Get my profile',
                            responses: {
                                '200': ok('Profile retrieved', schema('Profile')),
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '500': response('ServerError')
                            }
                        },
                        patch: {
                            tags: ['Employee: My profile'],
                            summary: 'Update my phone and/or address',
                            description: 'Employees can only change `phone` and `address`. Sending any HR-managed field ' +
                                '(name, email, department, position, status, ...) returns 403.',
                            requestBody: jsonBody('ProfileUpdate', { phone: '09171234599', address: 'Mandaue City, Cebu' }),
                            responses: {
                                '200': ok('Profile updated', schema('Profile')),
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '415': response('UnsupportedMediaType'),
                                '422': response('ValidationError'),
                                '500': response('ServerError')
                            }
                        }
                    },
                    '/api/me/avatar.php': {
                        post: {
                            tags: ['Employee: My profile'],
                            summary: 'Upload my profile photo',
                            description: 'JPG, PNG, GIF or WEBP, at most 3 MB. Replaces the previous photo.',
                            requestBody: {
                                required: true,
                                content: {
                                    'multipart/form-data': {
                                        schema: {
                                            type: 'object',
                                            required: ['avatar'],
                                            properties: {
                                                avatar: { type: 'string', format: 'binary' }
                                            }
                                        }
                                    }
                                }
                            },
                            responses: {
                                '200': ok('Photo updated', {
                                    type: 'object',
                                    properties: { avatar_url: { type: 'string', example: '/uploads/avatars/emp_1_3f9a1c2b7d4e.png?v=1762400000' } }
                                }),
                                '400': response('BadRequest'),
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '413': response('TooLarge'),
                                '415': response('UnsupportedMediaType'),
                                '500': response('ServerError')
                            }
                        }
                    },
                    '/api/me/password.php': {
                        post: {
                            tags: ['Employee: My profile'],
                            summary: 'Change my password',
                            description: 'The new password needs at least 8 characters and must differ from the current one.',
                            requestBody: jsonBody('PasswordChange', {
                                current_password: 'brandon123',
                                new_password: 'a-new-password',
                                confirm_password: 'a-new-password'
                            }),
                            responses: {
                                '200': {
                                    description: 'Password updated',
                                    content: json({
                                        type: 'object',
                                        properties: {
                                            success: { type: 'boolean', example: true },
                                            message: { type: 'string', example: 'Your password has been updated' }
                                        }
                                    })
                                },
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '415': response('UnsupportedMediaType'),
                                '422': response('ValidationError'),
                                '500': response('ServerError')
                            }
                        }
                    },

                    // ------------------------------------------------------------
                    // Employee: My leave
                    // ------------------------------------------------------------
                    '/api/me/leave_requests.php': {
                        get: {
                            tags: ['Employee: My leave'],
                            summary: 'List my leave requests',
                            description: 'Newest first.',
                            parameters: [leaveStatusParam],
                            responses: {
                                '200': list('Leave requests retrieved', 'LeaveRequest'),
                                '400': response('BadRequest'),
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '500': response('ServerError')
                            }
                        },
                        post: {
                            tags: ['Employee: My leave'],
                            summary: 'File a leave request',
                            description: 'Send `leave_type_id` (see `GET /api/leave_types.php`) or a custom `other_leave_type` name. ' +
                                'The start date cannot be in the past, and you can file 1 request every 24 hours (429 otherwise). ' +
                                'The request starts as Pending and HR is notified in realtime (`leave.filed`).',
                            requestBody: jsonBody('LeaveFile', {
                                leave_type_id: 1,
                                start_date: '2026-12-01',
                                end_date: '2026-12-03',
                                reason: 'Family vacation'
                            }),
                            responses: {
                                '201': ok('Leave request filed', schema('LeaveRequest'), {
                                    realtime_event: realtimeEvent('leave.filed'),
                                    next_request_available_at: { type: 'string', example: '2026-11-07 09:30:00', description: 'Philippine time' }
                                }),
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '415': response('UnsupportedMediaType'),
                                '422': response('ValidationError'),
                                '429': {
                                    description: 'Already filed a request in the last 24 hours',
                                    headers: {
                                        'Retry-After': { description: 'Seconds until the next request is allowed', schema: { type: 'integer' } }
                                    },
                                    content: json({
                                        allOf: [schema('Error'), {
                                            type: 'object',
                                            properties: {
                                                retry_after_seconds: { type: 'integer', example: 3600 },
                                                next_request_available_at: { type: 'string', example: '2026-11-07 09:30:00' }
                                            }
                                        }]
                                    })
                                },
                                '500': response('ServerError')
                            }
                        }
                    },
                    '/api/me/leave_request_cancel.php': {
                        post: {
                            tags: ['Employee: My leave'],
                            summary: 'Cancel my Pending leave request',
                            description: 'Only your own Pending requests can be cancelled. HR is notified in realtime (`leave.cancelled`).',
                            requestBody: jsonBody('LeaveCancel', { id: 1 }),
                            responses: {
                                '200': ok('Request cancelled', schema('LeaveRequest'), {
                                    realtime_event: realtimeEvent('leave.cancelled')
                                }),
                                '401': response('Unauthorized'),
                                '403': response('Forbidden'),
                                '404': response('NotFound'),
                                '409': response('Conflict'),
                                '415': response('UnsupportedMediaType'),
                                '422': response('ValidationError'),
                                '500': response('ServerError')
                            }
                        }
                    },

                    // ------------------------------------------------------------
                    // Realtime & shared
                    // ------------------------------------------------------------
                    '/api/realtime_token.php': {
                        get: {
                            tags: ['Realtime & shared'],
                            summary: 'Get a realtime WebSocket token',
                            description: 'Short-lived (5 minute) token for `ws://<host>:8090/ws?token=...`. ' +
                                'The channels come from the permission matrix: `hr` and `admin` get `hr` (every request), a `manager` gets one `employee:<id>` channel per person on their team, ' +
                                'and an `employee` gets only their own `employee:<id>`. A deactivated account or a role that has no leave access gets 401 or 403.',
                            responses: {
                                '200': {
                                    description: 'Token issued',
                                    content: json(schema('RealtimeToken'))
                                },
                                '401': { description: 'Not signed in', content: json(schema('RealtimeError')) },
                                '403': { description: 'This account has no realtime channel', content: json(schema('RealtimeError')) },
                                '503': { description: 'Realtime server is not configured', content: json(schema('RealtimeError')) }
                            }
                        }
                    },
                    '/api/leave_types.php': {
                        get: {
                            tags: ['Realtime & shared'],
                            summary: 'List active leave types',
                            description: 'The same choices as the "File leave" form.',
                            responses: {
                                '200': list('Leave types retrieved', 'LeaveType'),
                                '401': response('Unauthorized'),
                                '500': response('ServerError')
                            }
                        }
                    }
                },
                components: {
                    securitySchemes: {
                        sessionCookie: {
                            type: 'apiKey',
                            in: 'cookie',
                            name: 'PHPSESSID',
                            description: 'Set automatically when you sign in at /authentication/login.php in this browser.'
                        }
                    },
                    responses: {
                        BadRequest: { description: 'Invalid query parameter or request body', content: json(schema('Error')) },
                        Unauthorized: { description: 'Not signed in, or the account is inactive', content: json(schema('Error')) },
                        Forbidden: { description: 'Role not allowed, cross-site request, or field not editable by this role', content: json(schema('Error')) },
                        NotFound: { description: 'Not found', content: json(schema('Error')) },
                        Conflict: { description: 'The record is not in a state that allows this action', content: json(schema('Error')) },
                        TooLarge: { description: 'Upload or body too large', content: json(schema('Error')) },
                        UnsupportedMediaType: { description: 'Wrong Content-Type or file type', content: json(schema('Error')) },
                        ValidationError: { description: 'Validation failed; `errors` lists the problem with each field', content: json(schema('Error')) },
                        ServerError: { description: 'Internal server error', content: json(schema('Error')) }
                    },
                    schemas: {
                        Error: {
                            type: 'object',
                            properties: {
                                success: { type: 'boolean', example: false },
                                message: { type: 'string', example: 'Validation failed' },
                                errors: {
                                    type: 'object',
                                    additionalProperties: { type: 'string' },
                                    example: { date_hired: 'This field is required' }
                                }
                            }
                        },
                        Employee: {
                            type: 'object',
                            properties: {
                                id: { type: 'integer', example: 1 },
                                employee_id: { type: 'string', example: 'EMP-001' },
                                first_name: { type: 'string', example: 'Brandon' },
                                middle_name: nullableString(null),
                                last_name: { type: 'string', example: 'Babatuan' },
                                suffix: nullableString(null),
                                date_of_birth: nullableString('1998-04-15', { format: 'date' }),
                                gender: nullableString('Male', { enum: ['Male', 'Female', 'Other', null] }),
                                phone: nullableString('09171234501'),
                                email: nullableString('brandon@example.com'),
                                address: nullableString('Pulpogan 1, Consolacion, Cebu'),
                                department_id: { type: 'integer', nullable: true, example: 2 },
                                position_id: { type: 'integer', nullable: true, example: 2 },
                                manager_id: { type: 'integer', nullable: true, example: null, description: 'employees.id of the manager this person reports to' },
                                department_name: nullableString('Information Technology'),
                                position_title: nullableString('Software Developer'),
                                date_hired: { type: 'string', format: 'date', example: '2024-01-15' },
                                employment_type: { type: 'string', enum: ['Full-time', 'Part-time', 'Contract', 'Intern'] },
                                employment_status: { type: 'string', enum: ['Active', 'Inactive', 'On Leave', 'Suspended'] }
                            }
                        },
                        EmployeeCreate: {
                            type: 'object',
                            required: ['first_name', 'last_name', 'date_hired'],
                            additionalProperties: false,
                            properties: {
                                first_name: { type: 'string', maxLength: 100 },
                                middle_name: { type: 'string', maxLength: 100, nullable: true },
                                last_name: { type: 'string', maxLength: 100 },
                                suffix: { type: 'string', maxLength: 20, nullable: true },
                                date_of_birth: { type: 'string', format: 'date', nullable: true },
                                gender: { type: 'string', enum: ['Male', 'Female', 'Other'], nullable: true },
                                phone: { type: 'string', maxLength: 30, nullable: true, description: 'At least 7 digits; spaces, ( ) . - and a leading + allowed' },
                                email: { type: 'string', format: 'email', maxLength: 150, nullable: true, description: 'Must be unique (it can be used to sign in)' },
                                address: { type: 'string', maxLength: 1000, nullable: true },
                                department_id: { type: 'integer', minimum: 1, nullable: true },
                                position_id: { type: 'integer', minimum: 1, nullable: true },
                                manager_id: { type: 'integer', minimum: 1, nullable: true, description: 'Must be an employee with an active manager account' },
                                date_hired: { type: 'string', format: 'date' },
                                employment_type: { type: 'string', enum: ['Full-time', 'Part-time', 'Contract', 'Intern'], default: 'Full-time' },
                                employment_status: { type: 'string', enum: ['Active', 'Inactive', 'On Leave', 'Suspended'], default: 'Active' }
                            }
                        },
                        EmployeeUpdate: {
                            type: 'object',
                            minProperties: 1,
                            additionalProperties: false,
                            description: 'Any subset of the fields. first_name, last_name, date_hired, employee_id, employment_type and employment_status cannot be cleared.',
                            properties: {
                                employee_id: { type: 'string', maxLength: 20, example: 'EMP-010', description: 'Must be unique' },
                                first_name: { type: 'string', maxLength: 100 },
                                middle_name: { type: 'string', maxLength: 100, nullable: true },
                                last_name: { type: 'string', maxLength: 100 },
                                suffix: { type: 'string', maxLength: 20, nullable: true },
                                date_of_birth: { type: 'string', format: 'date', nullable: true },
                                gender: { type: 'string', enum: ['Male', 'Female', 'Other'], nullable: true },
                                phone: { type: 'string', maxLength: 30, nullable: true },
                                email: { type: 'string', format: 'email', maxLength: 150, nullable: true },
                                address: { type: 'string', maxLength: 1000, nullable: true },
                                department_id: { type: 'integer', minimum: 1, nullable: true },
                                position_id: { type: 'integer', minimum: 1, nullable: true },
                                manager_id: { type: 'integer', minimum: 1, nullable: true, description: 'Must be an employee with an active manager account, and not the employee themselves' },
                                date_hired: { type: 'string', format: 'date' },
                                employment_type: { type: 'string', enum: ['Full-time', 'Part-time', 'Contract', 'Intern'] },
                                employment_status: { type: 'string', enum: ['Active', 'Inactive', 'On Leave', 'Suspended'] }
                            }
                        },
                        EmployeeStatusChange: {
                            type: 'object',
                            required: ['id', 'status'],
                            additionalProperties: false,
                            properties: {
                                id: { type: 'integer', minimum: 1, description: 'Employee database ID' },
                                status: { type: 'string', enum: ['Inactive', 'Active'] }
                            }
                        },
                        EmployeeStatusResult: {
                            type: 'object',
                            properties: {
                                id: { type: 'integer', example: 3 },
                                employee_id: { type: 'string', example: 'EMP-003' },
                                employment_status: { type: 'string', example: 'Inactive' },
                                login_status: { type: 'string', example: 'Inactive' },
                                login_accounts_updated: { type: 'integer', example: 1, description: '0 when the employee has no login yet' }
                            }
                        },
                        LeaveRequest: {
                            type: 'object',
                            properties: {
                                id: { type: 'integer', example: 1 },
                                employee_id: { type: 'integer', example: 1, description: 'employees.id of the owner' },
                                employee_no: { type: 'string', example: 'EMP-001' },
                                first_name: { type: 'string', example: 'Brandon' },
                                middle_name: nullableString(null),
                                last_name: { type: 'string', example: 'Babatuan' },
                                leave_type_id: { type: 'integer', example: 1 },
                                leave_name: { type: 'string', example: 'Annual Leave' },
                                start_date: { type: 'string', format: 'date', example: '2026-12-01' },
                                end_date: { type: 'string', format: 'date', example: '2026-12-03' },
                                number_of_days: { type: 'number', example: 3 },
                                reason: { type: 'string', example: 'Family vacation' },
                                status: { type: 'string', enum: ['Pending', 'Approved', 'Rejected', 'Cancelled'] },
                                approved_by: { type: 'integer', nullable: true, description: 'users.id of the reviewer' },
                                approved_at: nullableString(null),
                                created_at: { type: 'string', example: '2026-11-06 09:30:00' }
                            }
                        },
                        LeaveReview: {
                            type: 'object',
                            required: ['id', 'action'],
                            additionalProperties: false,
                            properties: {
                                id: { type: 'integer', minimum: 1, description: 'Leave request ID' },
                                action: { type: 'string', enum: ['approve', 'reject'] }
                            }
                        },
                        LeaveFile: {
                            type: 'object',
                            required: ['start_date', 'end_date', 'reason'],
                            additionalProperties: false,
                            description: 'Send exactly one of leave_type_id or other_leave_type.',
                            properties: {
                                leave_type_id: { type: 'integer', minimum: 1 },
                                other_leave_type: { type: 'string', maxLength: 150, description: 'Custom leave type name (registered if new)' },
                                start_date: { type: 'string', format: 'date', description: 'Today or later' },
                                end_date: { type: 'string', format: 'date', description: 'On or after start_date' },
                                reason: { type: 'string', maxLength: 2000 }
                            }
                        },
                        LeaveCancel: {
                            type: 'object',
                            required: ['id'],
                            additionalProperties: false,
                            properties: {
                                id: { type: 'integer', minimum: 1, description: 'Leave request ID' }
                            }
                        },
                        LeaveType: {
                            type: 'object',
                            properties: {
                                id: { type: 'integer', example: 1 },
                                leave_name: { type: 'string', example: 'Annual Leave' },
                                description: nullableString('Paid time off for vacation, rest, and personal recreation'),
                                default_days: { type: 'number', example: 12 }
                            }
                        },
                        Profile: {
                            type: 'object',
                            properties: {
                                id: { type: 'integer', example: 1 },
                                employee_id: { type: 'string', example: 'EMP-001' },
                                first_name: { type: 'string', example: 'Brandon' },
                                middle_name: nullableString(null),
                                last_name: { type: 'string', example: 'Babatuan' },
                                suffix: nullableString(null),
                                full_name: { type: 'string', example: 'Brandon Babatuan' },
                                email: nullableString('brandon@example.com'),
                                phone: nullableString('09171234501'),
                                address: nullableString('Pulpogan 1, Consolacion, Cebu'),
                                gender: nullableString('Male'),
                                date_of_birth: nullableString('1998-04-15'),
                                department_name: nullableString('Information Technology'),
                                position_title: nullableString('Software Developer'),
                                date_hired: { type: 'string', example: '2024-01-15' },
                                employment_type: { type: 'string', example: 'Full-time' },
                                employment_status: { type: 'string', example: 'Active' },
                                avatar_url: { type: 'string', example: 'https://ui-avatars.com/api/?name=Brandon+Babatuan&background=4763ff&color=fff' },
                                account: {
                                    type: 'object',
                                    properties: {
                                        username: { type: 'string', example: 'brandon' },
                                        role: { type: 'string', example: 'employee' },
                                        status: { type: 'string', example: 'Active' }
                                    }
                                },
                                editable_fields: { type: 'array', items: { type: 'string' }, example: ['phone', 'address'] }
                            }
                        },
                        ProfileUpdate: {
                            type: 'object',
                            minProperties: 1,
                            additionalProperties: false,
                            properties: {
                                phone: { type: 'string', maxLength: 30, nullable: true, description: 'At least 7 digits; spaces, ( ) . - and a leading + allowed' },
                                address: { type: 'string', maxLength: 1000, nullable: true }
                            }
                        },
                        PasswordChange: {
                            type: 'object',
                            required: ['current_password', 'new_password', 'confirm_password'],
                            additionalProperties: false,
                            properties: {
                                current_password: { type: 'string', format: 'password' },
                                new_password: { type: 'string', format: 'password', minLength: 8 },
                                confirm_password: { type: 'string', format: 'password', minLength: 8 }
                            }
                        },
                        RealtimeToken: {
                            type: 'object',
                            properties: {
                                enabled: { type: 'boolean', example: true },
                                url: { type: 'string', example: 'ws://localhost:8090/ws' },
                                token: { type: 'string', description: 'base64url(claims).base64url(HMAC-SHA256)' },
                                role: { type: 'string', example: 'employee' },
                                expires_in: { type: 'integer', example: 300 }
                            }
                        },
                        RealtimeError: {
                            type: 'object',
                            properties: {
                                enabled: { type: 'boolean', example: false },
                                error: { type: 'string', example: 'Not logged in' }
                            }
                        }
                    }
                }
            }
        });
    </script>
</body>

</html>
