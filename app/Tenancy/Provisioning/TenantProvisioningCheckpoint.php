<?php

namespace App\Tenancy\Provisioning;

enum TenantProvisioningCheckpoint
{
    case AfterPhysicalCreateBeforeAuthorization;
    case AfterAuthorization;
    case AfterMarkerTableDdlBeforeLog;
    case AfterMarkerMigrationLoggedBeforeRow;
    case AfterMarkerRowBeforeManagerConnection;
    case BeforeTenantMigrationRun;
    case AfterTenantMigrationDdlBeforeLog;
    case AfterTenantMigrations;
    case BeforeOwnerProvision;
    case BeforeAuthorizationSync;
    case BeforeReferenceSeed;
    case BeforeActivation;
    case AfterActivationBeforeSuccessAudit;
}
