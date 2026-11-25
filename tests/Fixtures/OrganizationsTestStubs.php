<?php

declare(strict_types=1);

namespace App\Filament\Concerns {
    if (! trait_exists(EnforcesResourcePermissions::class)) {
        trait EnforcesResourcePermissions {}
    }
}

namespace App\Filament\Forms {
    if (
        ! class_exists(RolePermissionBuilder::class)
        && class_exists(\Liberu\Foundation\RolesPermissionsFilament\Forms\RolePermissionBuilder::class)
    ) {
        class_alias(
            \Liberu\Foundation\RolesPermissionsFilament\Forms\RolePermissionBuilder::class,
            RolePermissionBuilder::class,
        );
    }
}

namespace App\Services {
    if (! class_exists(AuditLogService::class)) {
        class AuditLogService
        {
            /** @var list<array{action: string, description: string, subject: mixed}> */
            public static array $recorded = [];

            public function record(string $action, string $description, mixed $subject = null): void
            {
                self::$recorded[] = [
                    'action' => $action,
                    'description' => $description,
                    'subject' => $subject,
                ];
            }

            public static function reset(): void
            {
                self::$recorded = [];
            }
        }
    }
}

namespace App\Support {
    if (! class_exists(SchemaGraph::class)) {
        class SchemaGraph
        {
            /** @var array<string, array<string, string>> */
            public static array $mockEdges = [];

            /**
             * @param  list<string>  $tables
             * @return array<string, array<string, string>>
             */
            public static function edges(array $tables): array
            {
                return self::$mockEdges;
            }
        }
    }
}

namespace App\Events {
    if (! class_exists(ContactUpdated::class)) {
        class ContactUpdated
        {
            public function __construct(public mixed $contact = null) {}
        }
    }
}

namespace App\Models {
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsToMany;

    if (! class_exists(Contact::class)) {
        class Contact extends Model
        {
            protected $table = 'org_test_contacts';

            protected $guarded = [];
        }
    }

    if (! class_exists(Branch::class)) {
        class Branch extends Model
        {
            protected $table = 'org_test_branches';

            protected $guarded = [];
        }
    }

    if (! class_exists(Territory::class)) {
        class Territory extends Model
        {
            protected $table = 'territories';

            protected $guarded = [];

            public function users(): BelongsToMany
            {
                return $this->belongsToMany(User::class, 'territory_user', 'territory_id', 'user_id');
            }
        }
    }

    if (! class_exists(Pipeline::class)) {
        class Pipeline extends Model
        {
            protected $table = 'org_test_pipelines';

            protected $guarded = [];
        }
    }

    if (! class_exists(Stage::class)) {
        class Stage extends Model
        {
            protected $table = 'org_test_stages';

            protected $guarded = [];
        }
    }

    if (! class_exists(Workflow::class)) {
        class Workflow extends Model
        {
            protected $table = 'org_test_workflows';

            protected $guarded = [];
        }
    }

    if (! class_exists(WorkflowAction::class)) {
        class WorkflowAction extends Model
        {
            protected $table = 'org_test_workflow_actions';

            protected $guarded = [];
        }
    }

    if (! class_exists(WorkflowCondition::class)) {
        class WorkflowCondition extends Model
        {
            protected $table = 'org_test_workflow_conditions';

            protected $guarded = [];
        }
    }

    if (! class_exists(WorkflowTrigger::class)) {
        class WorkflowTrigger extends Model
        {
            protected $table = 'org_test_workflow_triggers';

            protected $guarded = [];
        }
    }

    if (! class_exists(EmailTemplate::class)) {
        class EmailTemplate extends Model
        {
            protected $table = 'org_test_email_templates';

            protected $guarded = [];
        }
    }

    if (! class_exists(CustomField::class)) {
        class CustomField extends Model
        {
            protected $table = 'org_test_custom_fields';

            protected $guarded = [];
        }
    }

    if (! class_exists(Tag::class)) {
        class Tag extends Model
        {
            protected $table = 'org_test_tags';

            protected $guarded = [];
        }
    }

    if (! class_exists(KnowledgeBaseArticle::class)) {
        class KnowledgeBaseArticle extends Model
        {
            protected $table = 'org_test_kb_articles';

            protected $guarded = [];
        }
    }

    if (! class_exists(Chatbot::class)) {
        class Chatbot extends Model
        {
            protected $table = 'org_test_chatbots';

            protected $guarded = [];
        }
    }

    if (! class_exists(ReportBuilder::class)) {
        class ReportBuilder extends Model
        {
            protected $table = 'org_test_report_builders';

            protected $guarded = [];
        }
    }
}

namespace Liberu\Foundation\Organizations\Tests\Fixtures {
    use App\Models\Territory;
    use App\Models\User;
    use Illuminate\Database\Eloquent\Model;
    use Illuminate\Database\Eloquent\Relations\BelongsTo;
    use Illuminate\Database\Eloquent\Relations\BelongsToMany;
    use Illuminate\Database\Eloquent\Relations\HasMany;
    use Liberu\Foundation\Organizations\Models\Team;
    use Liberu\Foundation\Organizations\Traits\IsTenantModel;

    if (! class_exists(OrgTestUser::class)) {
        class OrgTestUser extends User
        {
            protected $table = 'users';

            public function getMorphClass(): string
            {
                return User::class;
            }

            public function ownedTeams(): HasMany
            {
                return $this->hasMany(Team::class, 'user_id');
            }

            public function currentTeam(): BelongsTo
            {
                return $this->belongsTo(Team::class, 'current_team_id');
            }

            public function teams(): BelongsToMany
            {
                return $this->belongsToMany(Team::class, 'team_user', 'user_id', 'team_id')
                    ->withPivot('role')
                    ->withTimestamps()
                    ->as('membership');
            }

            public function territories(): BelongsToMany
            {
                return $this->belongsToMany(Territory::class, 'territory_user', 'user_id', 'territory_id');
            }
        }
    }

    if (! class_exists(OrgTestTenantItem::class)) {
        class OrgTestTenantItem extends Model
        {
            use IsTenantModel;

            protected $table = 'org_test_tenant_items';

            protected $guarded = [];
        }
    }
}
