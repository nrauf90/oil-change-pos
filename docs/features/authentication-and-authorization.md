# Authentication and authorization

## Purpose and workflow

Staff sign in with email/password, are regenerated into an authenticated session, and are redirected to the first enabled navigation destination they may access. Logout invalidates and regenerates the session token. Owners manage staff in Filament; roles provide default permission bundles.

## Code map

- Routes: [`routes/web.php`](../../routes/web.php)
- Controller/request/view: [`LoginController`](../../app/Http/Controllers/Auth/LoginController.php), [`LoginRequest`](../../app/Http/Requests/LoginRequest.php), [`login.blade.php`](../../resources/views/auth/login.blade.php)
- Identity and policy vocabulary: [`User`](../../app/Models/User.php), [`Role`](../../app/Enums/Role.php), [`Permission`](../../app/Enums/Permission.php)
- Permission persistence: [`permission migration`](../../database/migrations/2026_08_26_175741_create_permission_tables.php), [`role seed migration`](../../database/migrations/2026_08_26_180100_seed_roles_and_permissions.php)
- Admin staff UI: [`UserResource`](../../app/Filament/Resources/Users/UserResource.php), [`UserForm`](../../app/Filament/Resources/Users/Schemas/UserForm.php), [`UsersTable`](../../app/Filament/Resources/Users/Tables/UsersTable.php)
- Tests: [`AuthenticationTest`](../../tests/Feature/AuthenticationTest.php), [`AuthorizationTest`](../../tests/Feature/AuthorizationTest.php), [`StaffLockoutTest`](../../tests/Feature/StaffLockoutTest.php), [`RolePermissionsTest`](../../tests/Feature/RolePermissionsTest.php)

## Permissions and invariants

- `users.*` controls staff administration; `roles.manage` controls the role matrix. Route actions additionally use their feature-specific permissions.
- Authorization must be enforced server-side. Never trust hidden controls or submitted role/owner fields.
- The owner role is immutable in the role matrix, and changes must not leave the shop without viable administrative access.
- Passwords are hashes; successful login regenerates the session, and logout invalidates it.

