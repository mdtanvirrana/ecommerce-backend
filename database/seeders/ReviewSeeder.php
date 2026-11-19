<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\Review;
use Carbon\Carbon;
use Illuminate\Database\Seeder;

class ReviewSeeder extends Seeder
{
    public function run(): void
    {
        if (Review::query()->exists()) {
            return;
        }

        $oneLife = Product::query()->where('slug', 'one-life-graphic-t-shirt')->first();
        if ($oneLife !== null) {
            $oneLifeReviews = [
                ['name' => 'Samantha D.', 'rating' => 5, 'days' => 1, 'featured' => false, 'comment' => "I absolutely love this t-shirt! The design is unique and the fabric feels so comfortable. As a fellow designer, I appreciate the attention to detail. It's become my favorite go-to shirt."],
                ['name' => 'Alex M.', 'rating' => 4, 'days' => 2, 'featured' => false, 'comment' => "The t-shirt exceeded my expectations! The colors are vibrant and the print quality is top-notch. Being a UI/UX designer myself, I'm quite picky about aesthetics, and this t-shirt definitely gets a thumbs up from me."],
                ['name' => 'Ethan R.', 'rating' => 4, 'days' => 3, 'featured' => false, 'comment' => "This t-shirt is a must-have for anyone who appreciates good design. The minimalistic yet stylish pattern caught my eye, and the fit is perfect. I can see the designer's touch in every aspect of this shirt."],
                ['name' => 'Olivia P.', 'rating' => 4, 'days' => 4, 'featured' => false, 'comment' => "As a UI/UX enthusiast, I value simplicity and functionality. This t-shirt not only represents those principles but also feels great to wear. It's evident that the designer poured their creativity into making this t-shirt stand out."],
                ['name' => 'Liam K.', 'rating' => 4, 'days' => 5, 'featured' => false, 'comment' => "This t-shirt is a fusion of comfort and creativity. The fabric is soft, and the design speaks volumes about the designer's skill. It's like wearing a piece of art that reflects my passion for both design and fashion."],
                ['name' => 'Ava H.', 'rating' => 5, 'days' => 6, 'featured' => false, 'comment' => "I'm not just wearing a t-shirt; I'm wearing a piece of design philosophy. The intricate details and thoughtful layout of the design make this shirt a conversation starter."],
                ['name' => 'Noah B.', 'rating' => 5, 'days' => 8, 'featured' => false, 'comment' => 'Heavyweight cotton feels premium straight out of the box and holds its shape after multiple washes.'],
                ['name' => 'Chloe W.', 'rating' => 4, 'days' => 10, 'featured' => false, 'comment' => 'True to size with a clean relaxed drape. Love the muted olive tone in person.'],
                ['name' => 'Daniel S.', 'rating' => 5, 'days' => 12, 'featured' => false, 'comment' => 'Fast delivery and crisp screen printing. Easily one of the nicest graphic tees in my closet.'],
                ['name' => 'Maya R.', 'rating' => 5, 'days' => 15, 'featured' => false, 'comment' => 'Bought one for myself and came back for a second color. Soft fabric and zero shrinkage.'],
            ];

            foreach ($oneLifeReviews as $r) {
                $ts = Carbon::now()->subDays($r['days']);
                Review::query()->create([
                    'product_id' => $oneLife->id,
                    'reviewer_name' => $r['name'],
                    'rating' => $r['rating'],
                    'comment' => $r['comment'],
                    'is_verified_purchase' => true,
                    'is_approved' => true,
                    'is_featured' => $r['featured'],
                    'created_at' => $ts,
                    'updated_at' => $ts,
                ]);
            }
        }

        // Featured Homepage "OUR HAPPY CUSTOMERS" testimonials linked to real products
        $featuredReviews = [
            [
                'slug' => 't-shirt-with-tape-details',
                'name' => 'Sarah M.',
                'rating' => 5,
                'comment' => "I'm blown away by the quality and style of the clothes I received from Shop.co. From casual wear to elegant dresses, every piece I've bought has exceeded my expectations.",
            ],
            [
                'slug' => 'skinny-fit-jeans',
                'name' => 'Alex K.',
                'rating' => 5,
                'comment' => 'Finding clothes that align with my personal style used to be a challenge until I discovered Shop.co. The range of options they offer is truly remarkable, catering to a variety of tastes and occasions.',
            ],
            [
                'slug' => 'checkered-shirt',
                'name' => 'James L.',
                'rating' => 5,
                'comment' => "As someone who's always on the lookout for unique fashion pieces, I'm thrilled to have stumbled upon Shop.co. The selection of clothes is not only diverse but also on-point with the latest trends.",
            ],
            [
                'slug' => 'vertical-striped-shirt',
                'name' => 'Mooen R.',
                'rating' => 5,
                'comment' => 'Fast delivery, accurate sizing, and incredible attention to stitching and fabric weight. Shop.co has completely replaced my usual weekend mall visits.',
            ],
            [
                'slug' => 'sleeve-striped-t-shirt',
                'name' => 'David T.',
                'rating' => 5,
                'comment' => 'Clean tailoring, breathable cotton, and colors that look exactly like the photos. Ordering and tracking through my account was seamless.',
            ],
            [
                'slug' => 'courage-graphic-t-shirt',
                'name' => 'Priya N.',
                'rating' => 5,
                'comment' => 'Every garment feels thoughtfully made. The discount coupons applied cleanly at checkout and my order arrived ahead of schedule.',
            ],
        ];

        foreach ($featuredReviews as $idx => $fr) {
            $product = Product::query()->where('slug', $fr['slug'])->first();
            if ($product === null) {
                continue;
            }
            $ts = Carbon::now()->subDays($idx + 1);
            Review::query()->create([
                'product_id' => $product->id,
                'reviewer_name' => $fr['name'],
                'rating' => $fr['rating'],
                'comment' => $fr['comment'],
                'is_verified_purchase' => true,
                'is_approved' => true,
                'is_featured' => true,
                'created_at' => $ts,
                'updated_at' => $ts,
            ]);
        }

        // Seed dynamic reviews across all other products so every product has real reviews & accurate counts
        $templates = [
            ['name' => 'Marcus V.', 'rating' => 5, 'comment' => 'Outstanding fabric weight and clean finish. Fits true to size right out of the package.'],
            ['name' => 'Elena C.', 'rating' => 4, 'comment' => 'Very comfortable for daily wear. Stitching and color depth look even better in person.'],
            ['name' => 'Ryan G.', 'rating' => 5, 'comment' => 'Great value for the craftsmanship. Washed twice already with zero fading or pilling.'],
            ['name' => 'Hannah L.', 'rating' => 4, 'comment' => 'Stylish cut and soft feel. Shipping was quick and packaging was neat.'],
            ['name' => 'Tariq H.', 'rating' => 5, 'comment' => 'Exactly matches the sizing guide and pairs effortlessly with casual or smart outfits.'],
            ['name' => 'Sophia K.', 'rating' => 4, 'comment' => 'Really impressed by the comfort and breathable material. Would definitely buy again.'],
            ['name' => 'Lucas P.', 'rating' => 5, 'comment' => 'Top-tier everyday staple. Subtle details make it stand out from ordinary basics.'],
        ];

        $allProducts = Product::query()->orderBy('id')->get();
        foreach ($allProducts as $pIndex => $product) {
            if ($product->slug !== 'one-life-graphic-t-shirt') {
                $targetCount = $pIndex < 8 ? 7 : 4;
                for ($i = 0; $i < $targetCount; $i++) {
                    $tpl = $templates[($pIndex + $i) % count($templates)];
                    $ts = Carbon::now()->subDays($i * 2 + 2);
                    Review::query()->create([
                        'product_id' => $product->id,
                        'reviewer_name' => $tpl['name'],
                        'rating' => $tpl['rating'],
                        'comment' => $tpl['comment'],
                        'is_verified_purchase' => true,
                        'is_approved' => true,
                        'is_featured' => false,
                        'created_at' => $ts,
                        'updated_at' => $ts,
                    ]);
                }
            }

            Review::syncProductRating($product->id);
        }
    }
}
