kiểm thử luồng thêm mới bình luận trên trình duyệt:
* không nhập gì -> ấn gửi -> hiện thông báo "cần nhập nội dung bình luận" : ĐẠT
* nhập comment chuẩn -> ấn gửi -> hiện thông báo đã thêm bình luận thành công từ server Backend : ĐẠT (nhưng cần A Long Backend xem xét lại cách thông báo, phải là "Thêm bình luận thành công, đang chờ Admin duyệt!")
* các trạng thái loading... lỗi.... hiển thị rõ ràng -> ĐẠT
Sửa bình luận:
ĐẠT nhưng sửa xong không đợi Admin duyệt đã hiển thị ngay: Lỗi BE bên anh Long, cần anh Long xem 
Xóa bình luận: ĐẠT
*chưa đăng nhập -> bắt buộc đăng nhập mới bình luận được -> ĐẠT
*chưa test các trường hợp lỗi bảo mật hoặc bị tấn công bảo mật để can thiệp vào bình luận của user khác vì đang tạm tin tưởng BE bên anh Long