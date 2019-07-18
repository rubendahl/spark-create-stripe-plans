<?php

namespace Gilbitron\Laravel\Spark\Console\Commands;

use Illuminate\Console\Command;
use Laravel\Cashier\Cashier;
use Laravel\Spark\Spark;
use Stripe;

class CreateStripePlans extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'spark:create-stripe-plans '
        . ' {--create : Create plans in Stripe} '
        . ' {--archived : Include archived plans} '
        . ' {--free : Include free plans} '
        . '';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Creates plans in Stripe based on the plans defined in Spark';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        Stripe\Stripe::setApiKey(config('services.stripe.secret'));

        $this->fetchExistingStripeProducts();

        $this->info('Creating user plans...');
        $this->createStripePlans(Spark::$plans);

        $this->info('Creating team plans...');
        $this->createStripePlans(Spark::$teamPlans);

        $this->info('Finished');
    }





    /**
     * Try adn fetch existing products from Stripe
     *
     * @param array $plans
     */
    public function fetchExistingStripeProducts() {
        $products = \Stripe\Product::all(['active'=>'true']);
        $this->existingStripeProducts = collect($products->getIterator());
        return $this->existingStripeProducts;
    }

    public function findExistingStripeProductFor($data) {
        return $this->existingStripeProducts->map(function ($prod) use ($data){
            return $prod->name == $data['name'];
        })->first();
    }


    /**
     * Try and create plans in Stripe
     *
     * @param array $plans
     */
    protected function createStripePlans($plans)
    {
        foreach ($plans as $plan) {
            if ($this->planExists($plan)) {
                $this->info('Stripe plan ' . $plan->id . ' already exists');
            } else {
                $this->info("Found Spark plan: " . $plan->name);
                if (!$plan->active && !$this->option('archived')) {
                    $this->line("- skipping archived plan " . $plan->name);
                    continue;
                }
                if ($plan->price === 0 && !$this->option('free')) {
                    $this->line("- skipping free plan " . $plan->name);
                    continue;
                }
                $this->createPlan($plan);
            }
        }
    }

    /**
     * Try and create a plan in Stripe
     *
     * @param mixed $plan
     */
    protected function createPlan($plan)
    {
        $name = $plan->name;

        $stripeDataDefaults = Spark::$details['stripe_data_defaults'];

        $longName = Spark::$details['product'] . ' ' . $plan->name . ' (' .
                        Cashier::usesCurrencySymbol() . $plan->price . ' ' . $plan->interval .
                        ')';


        $productData = [
            'name' => $name,
            'statement_descriptor' => $stripe_data_defaults['statement_descriptor'] ?? Spark::$details['vendor'],
        ];

        // TODO: Find existing product to avoid duplicate products.
        $existingProduct = $this->findExistingStripeProductFor($productData);
        if ($existingProduct) {
            $this->info(" <- existing Stripe product: " . print_r($existingProduct, true));
            $productData = $existingProduct->id;
        }

        $stripeData = [
           'id'                   => $plan->id,
           'nickname'             => $plan->id,
           'amount'               => $plan->price * 100,
           'interval'             => str_replace('ly', '', $plan->interval),
           'currency'             => Cashier::usesCurrency(),
           'product'              => $productData,
           'trial_period_days'    => $plan->trialDays,
        ];
        $this->info("-> prepared Stripe data: " . print_r($stripeData, true));

        if ($this->option('create')) {
            $stripePlan = Stripe\Plan::create($stripeData);
            $this->info('Stripe plan created: ' . $plan->id);
        }
    }

    /**
     * Check if a plan already exists
     *
     * @param $plan
     * @return bool
     */
    private function planExists($plan)
    {
        try {
            Stripe\Plan::retrieve($plan->id);
            return true;
        } catch (\Exception $e) {
        }

        return false;
    }
}
