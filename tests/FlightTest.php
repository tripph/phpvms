<?php

use App\Models\Enums\Days;
use App\Models\Flight;
use App\Models\User;
use App\Models\Bid;
use App\Repositories\SettingRepository;
use App\Services\FlightService;

class FlightTest extends TestCase
{
    protected $flightSvc, $settingsRepo;

    public function setUp()
    {
        parent::setUp();
        $this->addData('base');

        $this->flightSvc = app(FlightService::class);
        $this->settingsRepo = app(SettingRepository::class);
    }

    public function addFlight($user)
    {
        $flight = factory(App\Models\Flight::class)->create([
            'airline_id' => $user->airline_id
        ]);

        $flight->subfleets()->syncWithoutDetaching([
            factory(App\Models\Subfleet::class)->create([
                'airline_id' => $user->airline_id
            ])->id
        ]);

        return $flight;
    }

    public function testGetFlight()
    {
        $this->user = factory(App\Models\User::class)->create();
        $flight = $this->addFlight($this->user);

        $req = $this->get('/api/flights/' . $flight->id);
        $req->assertStatus(200);

        $body = $req->json()['data'];
        $this->assertEquals($flight->id, $body['id']);
        $this->assertEquals($flight->dpt_airport_id, $body['dpt_airport_id']);
        $this->assertEquals($flight->arr_airport_id, $body['arr_airport_id']);

        # Distance conversion
        $this->assertHasKeys($body['distance'], ['mi', 'nmi', 'km']);

        $this->get('/api/flights/INVALID', self::$auth_headers)
            ->assertStatus(404);
    }

    /**
     * Search based on all different criteria
     */
    public function testSearchFlight()
    {
        $this->user = factory(App\Models\User::class)->create();
        $flight = $this->addFlight($this->user);

        # search specifically for a flight ID
        $query = 'flight_id=' . $flight->id;
        $req = $this->get('/api/flights/search?' . $query);
        $req->assertStatus(200);
    }

    /**
     * Get the flight's route
     */
    public function testFlightRoute()
    {
        $this->user = factory(App\Models\User::class)->create();
        $flight = $this->addFlight($this->user);

        $route_count = random_int(4, 6);
        $route = factory(App\Models\Navdata::class, $route_count)->create();
        $route_text = implode(' ', $route->pluck('id')->toArray());

        $flight->route = $route_text;
        $flight->save();

        $res = $this->get('/api/flights/'.$flight->id.'/route');
        $res->assertStatus(200);
        $body = $res->json();

        $this->assertCount($route_count, $body['data']);

        $first_point = $body['data'][0];
        $this->assertEquals($first_point['id'], $route[0]->id);
        $this->assertEquals($first_point['name'], $route[0]->name);
        $this->assertEquals($first_point['type']['type'], $route[0]->type);
        $this->assertEquals(
            $first_point['type']['name'],
            \App\Models\Enums\NavaidType::label($route[0]->type)
        );
    }

    /**
     * Find all of the flights
     */
    public function testFindAllFlights()
    {
        $this->user = factory(App\Models\User::class)->create();
        factory(App\Models\Flight::class, 20)->create([
            'airline_id' => $this->user->airline_id
        ]);

        $res = $this->get('/api/flights');

        $body = $res->json();
        $this->assertEquals(2, $body['meta']['last_page']);

        $res = $this->get('/api/flights?page=2');
        $res->assertJsonCount(5, 'data');
    }

    /**
     * Test the bitmasks that they work for setting the day of week and
     * then retrieving by searching on those
     */
    public function testFindDaysOfWeek(): void
    {
        $this->user = factory(App\Models\User::class)->create();
        factory(App\Models\Flight::class, 20)->create([
            'airline_id' => $this->user->airline_id
        ]);

        $saved_flight = factory(App\Models\Flight::class)->create([
            'airline_id' => $this->user->airline_id,
            'days' => Days::getDaysMask([
                Days::SUNDAY,
                Days::THURSDAY
            ])
        ]);

        $flight = Flight::findByDays([Days::SUNDAY])->first();
        $this->assertTrue($flight->on_day(Days::SUNDAY));
        $this->assertTrue($flight->on_day(Days::THURSDAY));
        $this->assertFalse($flight->on_day(Days::MONDAY));
        $this->assertEquals($saved_flight->id, $flight->id);

        $flight = Flight::findByDays([Days::SUNDAY, Days::THURSDAY])->first();
        $this->assertEquals($saved_flight->id, $flight->id);

        $flight = Flight::findByDays([Days::WEDNESDAY, Days::THURSDAY])->first();
        $this->assertNull($flight);


    }

    /**
     * Make sure that flights are marked as inactive when they're out of the start/end
     * zones. also make sure that flights with a specific day of the week are only
     * active on those days
     */
    public function testDayOfWeekActive(): void
    {
        $this->user = factory(App\Models\User::class)->create();

        // Set it to Monday or Tuesday, depending on what today is
        if (date('N') === '1') { // today is a monday
            $days = Days::getDaysMask([Days::TUESDAY]);
        } else {
            $days = Days::getDaysMask([Days::MONDAY]);
        }

        factory(App\Models\Flight::class, 5)->create();
        $flight = factory(App\Models\Flight::class)->create([
            'days' => $days,
        ]);

        // Run the event that will enable/disable flights
        $event = new \App\Events\CronNightly();
        (new \App\Cron\Nightly\SetActiveFlights())->handle($event);

        $res = $this->get('/api/flights');
        $body = $res->json('data');

        $flights = collect($body)->where('id', $flight->id)->first();
        $this->assertNull($flights);
    }

    public function testStartEndDate(): void
    {
        $this->user = factory(App\Models\User::class)->create();

        factory(App\Models\Flight::class, 5)->create();
        $flight = factory(App\Models\Flight::class)->create([
            'start_date' => Carbon\Carbon::now('UTC')->subDays(1),
            'end_date'   => Carbon\Carbon::now('UTC')->addDays(1),
        ]);

        $flight_not_active = factory(App\Models\Flight::class)->create([
            'start_date' => Carbon\Carbon::now('UTC')->subDays(10),
            'end_date'   => Carbon\Carbon::now('UTC')->subDays(2),
        ]);

        // Run the event that will enable/disable flights
        $event = new \App\Events\CronNightly();
        (new \App\Cron\Nightly\SetActiveFlights())->handle($event);

        $res = $this->get('/api/flights');
        $body = $res->json('data');

        $flights = collect($body)->where('id', $flight->id)->first();
        $this->assertNotNull($flights);

        $flights = collect($body)->where('id', $flight_not_active->id)->first();
        $this->assertNull($flights);
    }

    public function testStartEndDateDayOfWeek(): void
    {
        $this->user = factory(App\Models\User::class)->create();

        // Set it to Monday or Tuesday, depending on what today is
        if (date('N') === '1') { // today is a monday
            $days = Days::getDaysMask([Days::TUESDAY]);
        } else {
            $days = Days::getDaysMask([Days::MONDAY]);
        }

        factory(App\Models\Flight::class, 5)->create();
        $flight = factory(App\Models\Flight::class)->create([
            'start_date' => Carbon\Carbon::now('UTC')->subDays(1),
            'end_date'   => Carbon\Carbon::now('UTC')->addDays(1),
            'days'       => Days::$isoDayMap[date('N')],
        ]);

        $flight_not_active = factory(App\Models\Flight::class)->create([
            'start_date' => Carbon\Carbon::now('UTC')->subDays(1),
            'end_date'   => Carbon\Carbon::now('UTC')->addDays(1),
            'days'       => $days,
        ]);

        // Run the event that will enable/disable flights
        $event = new \App\Events\CronNightly();
        (new \App\Cron\Nightly\SetActiveFlights())->handle($event);

        $res = $this->get('/api/flights');
        $body = $res->json('data');

        $flights = collect($body)->where('id', $flight->id)->first();
        $this->assertNotNull($flights);

        $flights = collect($body)->where('id', $flight_not_active->id)->first();
        $this->assertNull($flights);
    }

    /**
     *
     */
    public function testFlightSearchApi()
    {
        $this->user = factory(App\Models\User::class)->create();
        $flights = factory(App\Models\Flight::class, 10)->create([
            'airline_id' => $this->user->airline_id
        ]);

        $flight = $flights->random();

        $query = 'flight_number=' . $flight->flight_number;
        $req = $this->get('/api/flights/search?' . $query);
        $body = $req->json();

        $this->assertEquals($flight->id, $body['data'][0]['id']);
    }

    /**
     *
     */
    public function testAddSubfleet()
    {
        $subfleet = factory(App\Models\Subfleet::class)->create();
        $flight = factory(App\Models\Flight::class)->create();

        $fleetSvc = app(App\Services\FleetService::class);
        $fleetSvc->addSubfleetToFlight($subfleet, $flight);

        $flight->refresh();
        $found = $flight->subfleets()->get();
        $this->assertCount(1, $found);

        # Make sure it hasn't been added twice
        $fleetSvc->addSubfleetToFlight($subfleet, $flight);
        $flight->refresh();
        $found = $flight->subfleets()->get();
        $this->assertCount(1, $found);
    }

    /**
     * Add/remove a bid, test the API, etc
     * @throws \App\Services\Exception
     */
    public function testBids()
    {
        $user = factory(User::class)->create();
        $headers = $this->headers($user);

        $flight = $this->addFlight($user);

        $bid = $this->flightSvc->addBid($flight, $user);
        $this->assertEquals($user->id, $bid->user_id);
        $this->assertEquals($flight->id, $bid->flight_id);
        $this->assertTrue($flight->has_bid);

        # Refresh
        $flight = Flight::find($flight->id);
        $this->assertTrue($flight->has_bid);

        # Check the table and make sure thee entry is there
        $this->expectException(\App\Exceptions\BidExists::class);
        $this->flightSvc->addBid($flight, $user);

        $user->refresh();
        $this->assertEquals(1, $user->bids->count());

        # Query the API and see that the user has the bids
        # And pull the flight details for the user/bids
        $req = $this->get('/api/user', $headers);
        $req->assertStatus(200);

        $body = $req->json()['data'];
        $this->assertEquals(1, sizeof($body['bids']));
        $this->assertEquals($flight->id, $body['bids'][0]['flight_id']);

        $req = $this->get('/api/users/'.$user->id.'/bids', $headers);

        $body = $req->json()['data'];
        $req->assertStatus(200);
        $this->assertEquals($flight->id, $body[0]['id']);

        # Now remove the flight and check API

        $this->flightSvc->removeBid($flight, $user);

        $flight = Flight::find($flight->id);
        $this->assertFalse($flight->has_bid);

        $user->refresh();
        $bids = $user->bids()->get();
        $this->assertTrue($bids->isEmpty());

        $req = $this->get('/api/user', $headers);
        $req->assertStatus(200);

        $body = $req->json()['data'];
        $this->assertEquals($user->id, $body['id']);
        $this->assertEquals(0, sizeof($body['bids']));

        $req = $this->get('/api/users/'.$user->id.'/bids', $headers);
        $req->assertStatus(200);
        $body = $req->json()['data'];

        $this->assertCount(0, $body);
    }

    /**
     *
     */
    public function testMultipleBidsSingleFlight()
    {
        $this->settingsRepo->store('bids.disable_flight_on_bid', true);

        $user1 = factory(User::class)->create();
        $user2 = factory(User::class)->create([
            'airline_id' => $user1->airline_id
        ]);

        $flight = $this->addFlight($user1);

        # Put bid on the flight to block it off
        $this->flightSvc->addBid($flight, $user1);

        # Try adding again, should throw an exception
        $this->expectException(\App\Exceptions\BidExists::class);
        $this->flightSvc->addBid($flight, $user2);
    }

    /**
     * Add a flight bid VIA the API
     */
    public function testAddBidApi()
    {
        $this->user = factory(User::class)->create();
        $user2 = factory(User::class)->create();
        $flight = $this->addFlight($this->user);

        $uri = '/api/user/bids';
        $data = ['flight_id' => $flight->id];

        $body = $this->put($uri, $data);
        $body = $body->json('data');

        $this->assertEquals($body['flight_id'], $flight->id);

        # Now try to have the second user bid on it
        # Should return a 409 error
        $response = $this->put($uri, $data, [], $user2);
        $response->assertStatus(409);

        # Try now deleting the bid from the user
        $response = $this->delete($uri, $data);
        $body = $response->json('data');
        $this->assertCount(0, $body);
    }

    /**
     * Delete a flight and make sure all the bids are gone
     */
    public function testDeleteFlight()
    {
        $user = factory(User::class)->create();
        $headers = $this->headers($user);

        $flight = $this->addFlight($user);

        $bid = $this->flightSvc->addBid($flight, $user);
        $this->assertEquals($user->id, $bid->user_id);
        $this->assertEquals($flight->id, $bid->flight_id);
        $this->assertTrue($flight->has_bid);

        $this->flightSvc->deleteFlight($flight);

        $empty_flight = Flight::find($flight->id);
        $this->assertNull($empty_flight);

        # Make sure no bids exist
        $user_bids = Bid::where('flight_id', $flight->id)->get();

        #$this->assertEquals(0, $user_bid->count());

        # Query the API and see that the user has the bids
        # And pull the flight details for the user/bids
        $req = $this->get('/api/user', $headers);
        $req->assertStatus(200);

        $body = $req->json()['data'];
        $this->assertEquals($user->id, $body['id']);
        $this->assertCount(0, $body['bids']);

        $req = $this->get('/api/users/'.$user->id.'/bids', $headers);
        $req->assertStatus(200);

        $body = $req->json()['data'];
        $this->assertCount(0, $body);
    }
}
