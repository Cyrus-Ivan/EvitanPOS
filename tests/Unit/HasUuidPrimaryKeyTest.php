<?php

namespace Tests\Unit;

use App\Models\Concerns\HasUuidPrimaryKey;
use Illuminate\Database\Eloquent\Model;
use PHPUnit\Framework\TestCase;

class HasUuidPrimaryKeyTest extends TestCase
{
    public function test_it_configures_a_non_incrementing_string_uuid_key()
    {
        $model = new class extends Model
        {
            use HasUuidPrimaryKey;
        };

        $this->assertFalse($model->getIncrementing());
        $this->assertSame('string', $model->getKeyType());

        // newUniqueId() comes from HasUuids and must produce a valid UUID.
        $id = $model->newUniqueId();

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $id,
        );
    }
}
