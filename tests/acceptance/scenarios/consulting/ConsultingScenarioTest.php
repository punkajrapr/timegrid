<?php

use Illuminate\Foundation\Testing\DatabaseTransactions;

class ConsultingScenarioTest extends TestCase
{
    use DatabaseTransactions;
    use CreateBusiness, CreateUser, CreateContact, CreateAppointment, CreateService, CreateVacancy;

    protected $issuer;

    protected $business;

    protected $vacancy;

    protected $service;

    /**
     * @test
     */
    public function it_fits_for_consulting_scenario()
    {
        $this->arrangeScenario();

        $this->the_business_publishes_a_consulting_service();
        $this->the_business_publishes_vacancies();
        $this->a_user_subscribes_to_business();
        $this->the_user_queries_vacancies();
        $this->it_provides_available_times_for_requested_service_date();
        $this->the_user_takes_a_reservation();
        $this->the_user_sees_the_reservation_ticket();
    }

    public function the_business_publishes_a_consulting_service()
    {
        $this->service = $this->makeService([
            'name'     => 'OnSite 4hs Support',
            'duration' => 60 * 4,
            ]);

        $this->actingAs($this->owner);

        $this->call('POST', route('manager.business.service.store', $this->business), $this->service->toArray());

        $this->assertCount(1, $this->business->fresh()->services);
    }

    public function the_business_publishes_vacancies()
    {
        $this->actingAs($this->owner);

        $this->visit(route('manager.business.vacancy.create', $this->business));

        $sheet =
<<<EOD
onsite-4hs-support:1
 tue, thu, sat
  9-18
EOD;
        $this->type($sheet, 'vacancies');

        $this->press('Update');

        $this->assertResponseOk();
        $this->see('Availability registered successfully');
    }

    public function a_user_subscribes_to_business()
    {
        $contact = [
            'firstname' => 'John',
            'lastname'  => 'Doe',
            ];

        // And I am authenticated as the business owner
        $this->actingAs($this->issuer);

        // And I visit the business contact list section and fill the form
        $this->visit(route('user.businesses.home', $this->business))
             ->click('Subscribe');

        $this->see('Save')
             ->type($contact['firstname'], 'firstname')
             ->type($contact['lastname'], 'lastname')
             ->press('Save');

        // Then I see the contact registered
        $this->assertResponseOk();
        $this->see('Successfully saved')
             ->see("{$contact['firstname']} {$contact['lastname']}")
             ->see('Book appointment');
    }

    public function the_user_queries_vacancies()
    {
        $this->actingAs($this->issuer);

        $this->visit(route('user.booking.book', ['business' => $this->business]));

        $contact = [
            'firstname' => 'John',
            'lastname'  => 'Doe',
            ];

        $this->click('Subscribe');

        $this->see('Save')
             ->type($contact['firstname'], 'firstname')
             ->type($contact['lastname'], 'lastname')
             ->press('Save');

        $this->click('Book appointment');
    }

    public function it_provides_available_times_for_requested_service_date()
    {
        $this->actingAs($this->issuer);

        $this->service = $this->business->services()->first();

        $this->vacancy = $this->business->vacancies()->first();

        $this->get("api/vacancies/{$this->business->id}/{$this->service->id}/{$this->vacancy->date}");

        $this->assertResponseOk();
        $this->seeJsonContains(['times' => ['09:00', '09:30', '10:00', '10:30', '11:00', '11:30', '12:00', '12:30', '13:00', '13:30', '14:00']]);
    }

    public function the_user_takes_a_reservation()
    {
        $this->actingAs($this->issuer->fresh());

        $this->withoutMiddleware();
        $this->call('POST', route('user.booking.store', ['business' => $this->business]), [
            'businessId' => $this->business->id,
            'service_id' => $this->service->id,
            '_time'      => '11:30',
            '_date'      => $this->vacancy->date,
            'comments'   => 'test comments',
            ]);

        $this->seeInDatabase('appointments', ['business_id' => $this->business->id]);
    }

    public function the_user_sees_the_reservation_ticket()
    {
        $this->actingAs($this->issuer->fresh());

        $this->visit(route('user.businesses.home', ['business' => $this->business]));

        $this->see($this->service->name)
             ->see('Please arrive at 11:30 am')
             ->see($this->issuer->fresh()->appointments()->first()->code);
    }

    /**
     * Arrange Fixture.
     *
     * @return void
     */
    protected function arrangeScenario()
    {
        $this->owner = $this->createUser();

        $this->issuer = $this->createUser();

        $this->business = $this->createBusiness([
            'strategy' => 'timeslot',
            ]);

        $this->business->owners()->save($this->owner);

        $this->business->pref('vacancy_edit_advanced_mode', true);
        $this->business->pref('timeslot_step', 30);
        $this->business->pref('time_format', 'h:i a');
    }
}
