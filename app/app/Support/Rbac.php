<?php

namespace App\Support;

/**
 * Single source of truth for roles and permissions. Authorization checks
 * (Gate, controllers, views) must go through User::hasPermission()/hasRole(),
 * never read `is_admin` directly — that column is kept only for compatibility
 * with legacy rows and code paths (see docs/RBAC.md).
 */
class Rbac
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_VIEWER = 'viewer';
    public const ROLE_AUDITOR = 'auditor';

    public const ROLES = [self::ROLE_ADMIN, self::ROLE_OPERATOR, self::ROLE_VIEWER, self::ROLE_AUDITOR];

    public const ROLE_LABELS = [
        self::ROLE_ADMIN => 'Administrador',
        self::ROLE_OPERATOR => 'Operador',
        self::ROLE_VIEWER => 'Somente leitura',
        self::ROLE_AUDITOR => 'Auditor',
    ];

    public const PERMISSIONS = [
        'dashboard.view',
        'sites.view', 'sites.manage', 'sites.delete',
        'devices.view', 'devices.manage', 'devices.delete',
        'credentials.view', 'credentials.manage', 'credentials.disable',
        'ftp.view', 'ftp.manage', 'ftp.delete',
        'backup_policies.view', 'backup_policies.manage', 'backup_policies.delete',
        'backup_executions.view', 'backup_executions.run',
        'backup_artifacts.view', 'backup_artifacts.download', 'backup_artifacts.delete',
        'audit.view',
        'settings.view', 'settings.manage',
        'users.view', 'users.manage',
        'system_health.view',
        'reports.view', 'reports.export',
    ];

    private const OPERATOR_PERMISSIONS = [
        'dashboard.view',
        'sites.view', 'sites.manage',
        'devices.view', 'devices.manage',
        'credentials.view', 'credentials.manage',
        'ftp.view', 'ftp.manage',
        'backup_policies.view', 'backup_policies.manage',
        'backup_executions.view', 'backup_executions.run',
        'backup_artifacts.view', 'backup_artifacts.download',
        'system_health.view',
        'reports.view', 'reports.export',
    ];

    private const VIEWER_PERMISSIONS = [
        'dashboard.view',
        'sites.view',
        'devices.view',
        'credentials.view',
        'ftp.view',
        'backup_policies.view',
        'backup_executions.view',
        'backup_artifacts.view', 'backup_artifacts.download',
        'system_health.view',
        'reports.view', 'reports.export',
    ];

    private const AUDITOR_PERMISSIONS = [
        'dashboard.view',
        'audit.view',
        'sites.view',
        'devices.view',
        'credentials.view',
        'ftp.view',
        'backup_policies.view',
        'backup_executions.view',
        'backup_artifacts.view',
        'system_health.view',
        'reports.view', 'reports.export',
    ];

    /** @return list<string> */
    public static function permissionsForRole(?string $role): array
    {
        return match ($role) {
            self::ROLE_ADMIN => self::PERMISSIONS,
            self::ROLE_OPERATOR => self::OPERATOR_PERMISSIONS,
            self::ROLE_VIEWER => self::VIEWER_PERMISSIONS,
            self::ROLE_AUDITOR => self::AUDITOR_PERMISSIONS,
            default => [],
        };
    }

    public static function isValidRole(string $role): bool
    {
        return in_array($role, self::ROLES, true);
    }

    public static function roleLabel(string $role): string
    {
        return self::ROLE_LABELS[$role] ?? $role;
    }
}
