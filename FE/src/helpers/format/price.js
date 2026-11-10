//hoàng đẹp zai
// Hàm chuyển đổi giá tiền sang định dạng tiền Việt Nam (VND)
export const formatPrice = (price) => {

    // Sử dụng Intl.NumberFormat để định dạng số theo quy chuẩn Việt Nam
    return new Intl.NumberFormat('vi-VN', {

        // style: 'currency' có nghĩa là định dạng theo kiểu tiền tệ
        style: 'currency',

        // currency: 'VND' xác định đơn vị tiền tệ là Việt Nam Đồng
        currency: 'VND'

    }).format(price); // Nhận giá tiền và trả về chuỗi đã định dạng
};