<?php

namespace App\Enums;

enum ShopLifecycleEvent: string
{
    case ProvisioningStarted = 'shop.provisioning_started';
    case ProvisioningSucceeded = 'shop.provisioning_succeeded';
    case ProvisioningFailed = 'shop.provisioning_failed';
    case TenantInstallationAuthorized = 'tenant.installation_authorized';
    case Suspended = 'shop.suspended';
    case Reactivated = 'shop.reactivated';
    case FeatureEnabled = 'shop.feature_enabled';
    case FeatureDisabled = 'shop.feature_disabled';
    case MigrationSucceeded = 'tenant.migration_succeeded';
    case MigrationFailed = 'tenant.migration_failed';
    case ExistingDatabaseAdopted = 'tenant.existing_database_adopted';
    case DatabaseEndpointRotationStarted = 'tenant.database_endpoint_rotation_started';
    case DatabaseEndpointRotationCompleted = 'tenant.database_endpoint_rotation_completed';
    case DatabaseEndpointRotationSuperseded = 'tenant.database_endpoint_rotation_superseded';
}
