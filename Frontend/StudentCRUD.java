package src.project1;

import java.sql.*;
import java.io.BufferedReader;
import java.io.InputStreamReader;

public class StudentCRUD {
    public static void main(String[] args) {
        try {
            Class.forName("com.mysql.cj.jdbc.Driver");
            Connection con = DriverManager.getConnection(
                "jdbc:mysql://localhost:3306/college", "root", ""
            );
            BufferedReader br = new BufferedReader(new InputStreamReader(System.in));
            String choice;
            
            do {
                System.out.println("\n1. Insert\n2. Update\n3. Delete\n4. Display\n5. Exit");
                System.out.print("Enter your choice: ");
                choice = br.readLine().trim();
                
                switch (choice) {
                    case "1":
                        System.out.print("Enter id: ");
                        int id = Integer.parseInt(br.readLine());
                        System.out.print("Enter name: ");
                        String name = br.readLine();
                        System.out.print("Enter city: ");
                        String city = br.readLine();
                        System.out.print("Enter state: ");
                        String state = br.readLine();
                        System.out.print("Enter pincode: ");
                        String pin = br.readLine();

                        PreparedStatement ps1 = con.prepareStatement(
                            "INSERT INTO student (id, name, city, state, pincode) VALUES (?, ?, ?, ?, ?)"
                        );
                        ps1.setInt(1, id);
                        ps1.setString(2, name);
                        ps1.setString(3, city);
                        ps1.setString(4, state);
                        ps1.setString(5, pin);
                        int ins = ps1.executeUpdate();
                        System.out.println(ins + " record inserted");
                        ps1.close();
                        break;

                    case "2":
                        System.out.print("Enter id to update: ");
                        id = Integer.parseInt(br.readLine());
                        System.out.print("Enter new name: ");
                        name = br.readLine();
                        System.out.print("Enter new city: ");
                        city = br.readLine();
                        System.out.print("Enter new state: ");
                        state = br.readLine();
                        System.out.print("Enter new pincode: ");
                        pin = br.readLine();

                        PreparedStatement ps2 = con.prepareStatement(
                            "UPDATE student SET name=?, city=?, state=?, pincode=? WHERE id=?"
                        );
                        ps2.setString(1, name);
                        ps2.setString(2, city);
                        ps2.setString(3, state);
                        ps2.setString(4, pin);
                        ps2.setInt(5, id);
                        int upd = ps2.executeUpdate();
                        System.out.println(upd + " record updated");
                        ps2.close();
                        break;

                    case "3":
                        System.out.print("Enter id to delete: ");
                        id = Integer.parseInt(br.readLine());
                        PreparedStatement ps3 = con.prepareStatement(
                            "DELETE FROM student WHERE id=?"
                        );
                        ps3.setInt(1, id);
                        int del = ps3.executeUpdate();
                        System.out.println(del + " record deleted");
                        ps3.close();
                        break;

                    case "4":
                        Statement stmt = con.createStatement();
                        ResultSet rs = stmt.executeQuery("SELECT * FROM student");
                        System.out.println("ID\tName\tCity\tState\tPincode");
                        while (rs.next()) {
                            System.out.printf("%d\t%s\t%s\t%s\t%s\n",
                                rs.getInt("id"),
                                rs.getString("name"),
                                rs.getString("city"),
                                rs.getString("state"),
                                rs.getString("pincode")
                            );
                        }
                        rs.close();
                        stmt.close();
                        break;

                    case "5":
                        System.out.println("Exiting...");
                        break;

                    default:
                        System.out.println("Invalid choice.");
                }
            } while (!choice.equals("5"));

            br.close();
            con.close();
        } catch (Exception e) {
            System.out.println("Error: " + e);
        }
    }
}
