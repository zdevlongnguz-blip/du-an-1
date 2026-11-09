<?php
class ProductController{
    private Product $product;
    public function __construct()
    {
        $this->product = new Product();
    }

    public function index(){
        $search = $_GET['search'] ?? $_GET['q'] ?? '';
        $category = $_GET['category'] ?? '';
        if (!is_string($category) || strlen(trim($category)) > 255) {
            response_json([
                'status' => 'error',
                'message' => 'Slug danh mục không hợp lệ',
            ], 400);
        }
        $category = trim($category);
        $category = $category === '' ? null : $category;
        $page = $_GET['page'] ?? 1;
        $perPage = $_GET['per_page'] ?? $_GET['perPage'] ?? 8;

        $products = $this->product->getFiltered(
            $search,
            $category,
            $page,
            $perPage
        );

        response_json([
            'status' => 'success',
            'products' => $products,
            'filters' => [
                'search' => $search,
                'category' => $category,
                'page' => $page,
                'per_page' => $perPage,
            ],
        ]);
    }

    /**
     * Lấy tối đa 5 sản phẩm được xem nhiều nhất hoặc bán nhiều nhất.
     * Mặc định sắp xếp theo lượt bán; sort_by sai trả HTTP 400.
     */
    public function top()
    {
        $sortBy = $_GET['sort_by'] ?? 'sales';
        if (!is_string($sortBy) || !in_array($sortBy, ['views', 'sales'], true)) {
            response_json([
                'status' => 'error',
                'message' => 'sort_by phải là views hoặc sales',
            ], 400);
        }

        $products = $this->product->getTop($sortBy);

        response_json([
            'status' => 'success',
            'sort_by' => $sortBy,
            'products' => $products,
        ]);
    }

    /**
     * Trả về chi tiết sản phẩm theo slug, gồm ảnh thumbnail và album ảnh.
     * Thiếu slug trả HTTP 400; không có sản phẩm trả HTTP 404.
     */
    public function detail()
    {
        $slug = $_GET['slug'] ?? null;
        if (!is_string($slug) || trim($slug) === '' || strlen(trim($slug)) > 255) {
            response_json(['status' => 'error', 'message' => 'Slug sản phẩm không hợp lệ'], 400);
        }

        $product = $this->product->getBySlug(trim($slug));
        if ($product === null) {
            response_json(['status' => 'error', 'message' => 'Không tìm thấy sản phẩm'], 404);
        }

        response_json([
            'status' => 'success',
            'product' => $product,
        ]);
    }

    //test mock data

    public function camChim(){
        $camChims = [
            [
                'id' => 1,
                'name' => 'Trần Hà Linh',
                'image' => 'https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcRWRUgFJDVfIftWG6y8iggQuPYJSg0kP8FpqSuxMKGu4OD708czJy5mX9gF&s=10'
            ],
            [
                'id' => 2,
                'name' => 'Linh Tây',
                'image' => 'https://benhvienthammysiamthailand.vn/wp-content/uploads/2024/04/1-copy-7-1.jpg'  
            ]
        ];
        response_json([
            'status' => 'success',
            'camChims' => $camChims
        ]);
    }

}