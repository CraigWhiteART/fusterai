/** Mirrors User::isAdmin() (the `manage-settings` gate): admins and super admins. */
export function isAdminRole(role: string | null | undefined): boolean {
    return role === 'admin' || role === 'super_admin';
}
