/* @@var $factory \Illuminate\Database\Eloquent\Factory */

use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Database\Eloquent\Factories\Factory;
use {{ $model_class }};

class {{ $factory_class_name }} extends Factory
{
    use WithFaker;

    /**
     * The name of the factory's corresponding model.
     *
     * @var string
     */
    protected $model = {{ $model_class_short }}::class;

    /**
     * Define the model's default state.
     *
     * @return array
     */
    public function definition(): array
    {
        return [
@foreach($properties as $name => $property)
            '{{ $name }}' => {!! $property !!},
@endforeach
        ];
    }
}
